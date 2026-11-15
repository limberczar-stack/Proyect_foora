<?php

/**
 * Prueba de concurrencia del límite planes.max_mesas con procesos PHP reales contra MySQL.
 *
 * No es parte de phpunit (la suite usa SQLite en memoria). Ver tests/Concurrencia/README.md.
 *
 *   php tests/Concurrencia/limite_mesas.php            # todos los escenarios
 *   php tests/Concurrencia/limite_mesas.php --procesos=12
 *
 * Escenarios (el local queda a 1 mesa de su límite y N procesos intentan a la vez):
 * - crear:      N procesos llaman a GestionarMesas::crearMesa         → debe quedar exactamente en el límite.
 * - varias:     N procesos llaman a GestionarMesas::agregarVarias (1) → igual.
 * - reactivar:  N procesos reactivan N mesas distintas                → igual.
 * - control:    la misma secuencia "contar y luego insertar" SIN bloquear el restaurante
 *               → debe PASARSE del límite. Si no se pasa, la prueba no demuestra nada y falla.
 *
 * Para que la carrera sea reproducible, cada proceso espera 150 ms justo después de contar las
 * mesas activas (DB::listen), en todos los escenarios por igual.
 *
 * Seguridad: aborta si la base de datos no se llama foora_concurrencia, porque la borra entera
 * (migrate:fresh) al empezar.
 */

use App\Models\Area;
use App\Models\Mesa;
use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\User;
use App\Services\GestionarMesas;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/_arranque.php';

const LIMITE = 5;
const PAUSA_TRAS_CONTAR_US = 150_000;

arrancar();

$argumentos = array_slice($argv, 1);

if (($argumentos[0] ?? null) === 'trabajador') {
    exit(trabajador(...array_slice($argumentos, 1)));
}

exit(coordinar(procesos: (int) (opcion($argumentos, 'procesos') ?? 8)));

/* -------------------------------------------------------------------------- */

/**
 * @return int código de salida: 0 si todo salió como se esperaba
 */
function coordinar(int $procesos): int
{
    echo 'Base: '.BASE_ESPERADA.". Preparando con migrate:fresh…\n";
    reconstruirBase();

    $escenarios = [
        'crear' => ['esperado' => 'en_limite'],
        'varias' => ['esperado' => 'en_limite'],
        'reactivar' => ['esperado' => 'en_limite'],
        'control' => ['esperado' => 'pasa_limite'],
    ];

    $fallos = 0;

    foreach ($escenarios as $escenario => ['esperado' => $esperado]) {
        [$restaurante, $admin] = prepararLocal($escenario, $procesos);

        $inicio = horaDeLargada();
        $resultados = lanzar(__FILE__, array_map(fn (int $numero): array => [$escenario, (string) $admin->id, (string) $numero, $inicio], range(1, $procesos)));

        $activas = Mesa::withoutGlobalScopes()->where('restaurante_id', $restaurante->id)->where('activa', true)->count();
        $cuenta = array_count_values(array_column($resultados, 'resultado'));
        $errores = array_values(array_filter($resultados, fn (array $r): bool => $r['resultado'] === 'error'));

        // Con bloqueo, cada rechazo tiene que ser por el límite del plan, no por otra cosa.
        $rechazosPorLimite = array_filter($resultados, fn (array $r): bool => $r['resultado'] === 'rechazado' && str_contains($r['mensaje'], 'permite hasta '.LIMITE.' mesas activas'));

        $ok = match ($esperado) {
            'en_limite' => $activas === LIMITE && $errores === [] && ($cuenta['hecho'] ?? 0) === 1 && count($rechazosPorLimite) === $procesos - 1,
            'pasa_limite' => $activas > LIMITE,
        };

        printf("%-10s %d procesos · límite %d · activas al final: %d · hechos: %d · rechazados: %d · errores: %d → %s\n",
            $escenario, $procesos, LIMITE, $activas, $cuenta['hecho'] ?? 0, $cuenta['rechazado'] ?? 0, count($errores),
            $ok ? 'OK' : 'FALLÓ');

        foreach ($errores as $error) {
            echo "    error: {$error['mensaje']}\n";
        }

        if ($esperado === 'pasa_limite' && ! $ok) {
            echo "    El control negativo no reprodujo la carrera: la prueba no demuestra que el bloqueo sea necesario.\n";
        }

        $fallos += $ok ? 0 : 1;
    }

    echo $fallos === 0 ? "\nTodo bien: con bloqueo nunca se pasa el límite; sin bloqueo sí.\n" : "\n{$fallos} escenario(s) fallaron.\n";

    return $fallos === 0 ? 0 : 1;
}

/**
 * Un local nuevo a una mesa de su límite. En "reactivar", además, N mesas desactivadas.
 *
 * @return array{0: Restaurante, 1: User}
 */
function prepararLocal(string $escenario, int $procesos): array
{
    $plan = Plan::firstOrCreate(['nombre' => 'Concurrencia'], ['precio_mensual' => 1, 'max_mesas' => LIMITE]);

    $restaurante = new Restaurante(['nombre' => "Local {$escenario}", 'slug' => "local-{$escenario}", 'ciudad' => 'Lima', 'estado_local' => 'abierto', 'activo' => true, 'plan_id' => $plan->id]);
    $restaurante->forceFill(['modulos' => ['mesas']])->save();

    $admin = new User(['name' => "Admin {$escenario}", 'email' => "admin-{$escenario}@concurrencia.test", 'password' => 'password', 'rol' => 'admin', 'activo' => true]);
    $admin->forceFill(['restaurante_id' => $restaurante->id])->save();

    $area = new Area(['nombre' => 'Salón', 'activa' => true]);
    $area->forceFill(['restaurante_id' => $restaurante->id, 'orden' => 0])->save();

    $crear = function (string $codigo, bool $activa) use ($restaurante, $area): void {
        $mesa = new Mesa(['codigo' => $codigo, 'capacidad' => 4, 'estado' => 'disponible', 'activa' => $activa]);
        $mesa->forceFill(['restaurante_id' => $restaurante->id, 'area_id' => $area->id])->save();
    };

    foreach (range(1, LIMITE - 1) as $numero) {
        $crear("A{$numero}", true);
    }

    if ($escenario === 'reactivar') {
        foreach (range(1, $procesos) as $numero) {
            $crear("R{$numero}", false);
        }
    }

    return [$restaurante, $admin];
}

/**
 * Un proceso: espera la largada y hace su intento. Imprime una línea JSON con el resultado.
 */
function trabajador(string $escenario, string $adminId, string $numero, string $inicio): int
{
    $admin = User::findOrFail((int) $adminId);
    Auth::setUser($admin);

    $areaId = Area::withoutGlobalScopes()->where('restaurante_id', $admin->restaurante_id)->value('id');

    // Ensancha la ventana entre "contar mesas activas" e "insertar", igual en todos los escenarios.
    DB::listen(function (QueryExecuted $consulta): void {
        if (preg_match('/select count\(\*\) as aggregate from `mesas`.*`activa` = \?/i', $consulta->sql)) {
            usleep(PAUSA_TRAS_CONTAR_US);
        }
    });

    esperarLargada($inicio);

    $gestion = app(GestionarMesas::class);

    try {
        match ($escenario) {
            'crear' => $gestion->crearMesa($admin, ['codigo' => "C{$numero}", 'capacidad' => 4, 'area_id' => $areaId]),
            'varias' => $gestion->agregarVarias($admin, ['area_id' => $areaId, 'prefijo' => "V{$numero}-", 'desde' => 1, 'hasta' => 1, 'capacidad' => 4]),
            'reactivar' => $gestion->cambiarActivaMesa($admin, Mesa::where('codigo', "R{$numero}")->sole(), true),
            'control' => crearSinBloqueo($admin, $areaId, "S{$numero}"),
        };

        echo json_encode(['resultado' => 'hecho', 'mensaje' => '']), "\n";
    } catch (ValidationException $e) {
        echo json_encode(['resultado' => 'rechazado', 'mensaje' => collect($e->errors())->flatten()->first()]), "\n";
    } catch (Throwable $e) {
        echo json_encode(['resultado' => 'error', 'mensaje' => $e::class.': '.$e->getMessage()]), "\n";
    }

    return 0;
}

/**
 * Control negativo: la misma regla que GestionarMesas (contar las activas y comparar con el límite
 * dentro de una transacción), pero sin lockForUpdate sobre el restaurante.
 */
function crearSinBloqueo(User $admin, int $areaId, string $codigo): void
{
    DB::transaction(function () use ($admin, $areaId, $codigo): void {
        $restaurante = Restaurante::with('plan')->findOrFail($admin->restaurante_id);
        $activas = Mesa::where('restaurante_id', $restaurante->id)->where('activa', true)->count();

        if ($activas >= $restaurante->plan->max_mesas) {
            throw ValidationException::withMessages(['mesas' => 'Límite alcanzado.']);
        }

        $mesa = new Mesa(['codigo' => $codigo, 'capacidad' => 4, 'estado' => 'disponible', 'activa' => true]);
        $mesa->forceFill(['restaurante_id' => $restaurante->id, 'area_id' => $areaId])->save();
    });
}
