<?php

/**
 * Prueba de concurrencia de las cuentas de salón con procesos PHP reales contra MySQL.
 *
 * No es parte de phpunit (la suite usa SQLite en memoria). Ver tests/Concurrencia/README.md.
 *
 *   php tests/Concurrencia/cuentas.php
 *   php tests/Concurrencia/cuentas.php --procesos=12
 *
 * Escenarios:
 * - misma_mesa:  N procesos abren una cuenta en la misma mesa             → una sola cuenta.
 * - cruzadas:    por cada par de mesas, un proceso abre A+B y otro B+A   → gana uno, sin interbloqueo.
 * - numeracion:  N procesos abren cuentas en mesas distintas             → números 1..N, sin huecos ni duplicados.
 *
 * Cada escenario corre en tres modos, y antes de cada corrida la base se reconstruye (migrate:fresh):
 * - bloqueo:      GestionarCuentas tal cual. Debe salir limpio: un ganador y el resto con 409 por la regla.
 * - sin_bloqueo:  el mismo código sin lockForUpdate sobre el restaurante (control negativo 1). Los índices
 *                 únicos siguen evitando el dato malo, pero ya no es ordenado: los perdedores chocan con
 *                 el índice en vez de recibir la regla, y se pierden aperturas legítimas.
 * - sin_indices:  sin bloqueo y sin los índices uq_ocu_mesa_abierta y uq_cuenta_num (control negativo 2).
 *
 * El control 2 se corre con dos ritmos, porque la carrera tiene dos formas y fallan distinto:
 * - a_la_vez:    todos escriben al mismo tiempo. No llegan a ocupar dos veces la mesa: InnoDB los
 *                interbloquea (cada uno tiene un bloqueo compartido de la mesa por la clave foránea y
 *                todos quieren actualizarla) y mata a todos menos uno con el error 1213. Es el
 *                interbloqueo que el bloqueo del restaurante evita.
 * - escalonado:  cada proceso tarda más que el anterior entre leer y escribir, así que escribe con una
 *                lectura vieja cuando el anterior ya terminó. Aquí sí se corrompe el dato: varias
 *                cuentas abiertas en la misma mesa.
 *
 * Un control negativo que NO reproduce el problema cuenta como fallo: la prueba no demostraría nada.
 *
 * Para que la carrera sea reproducible, cada proceso espera justo después de comprobar que las mesas
 * están libres y después de leer el último número de cuenta: 150 ms todos (a_la_vez), o 150 ms más
 * 300 ms por cada proceso anterior (escalonado). La espera no depende del modo.
 *
 * Seguridad: aborta si la base de datos no se llama foora_concurrencia, porque la borra entera.
 */

use App\Models\Area;
use App\Models\Cuenta;
use App\Models\Mesa;
use App\Models\OcupacionMesa;
use App\Models\Restaurante;
use App\Models\User;
use App\Services\GestionarCuentas;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require __DIR__.'/_arranque.php';

const PAUSA_US = 150_000;
const ESCALON_US = 300_000;

/** Corridas de cada escenario: [modo, ritmo]. */
const CORRIDAS = [
    'misma_mesa' => [['bloqueo', 'a_la_vez'], ['bloqueo', 'escalonado'], ['sin_bloqueo', 'a_la_vez'], ['sin_indices', 'a_la_vez'], ['sin_indices', 'escalonado']],
    'cruzadas' => [['bloqueo', 'a_la_vez'], ['bloqueo', 'escalonado'], ['sin_bloqueo', 'a_la_vez'], ['sin_indices', 'a_la_vez'], ['sin_indices', 'escalonado']],
    'numeracion' => [['bloqueo', 'a_la_vez'], ['sin_bloqueo', 'a_la_vez'], ['sin_indices', 'a_la_vez']],
];

arrancar();

/**
 * El mismo servicio, sin el bloqueo que ordena las operaciones del local.
 */
class GestionarCuentasSinBloqueo extends GestionarCuentas
{
    protected function bloquearRestaurante(User $actor): Restaurante
    {
        return Restaurante::findOrFail($actor->restaurante_id);
    }
}

$argumentos = array_slice($argv, 1);

if (($argumentos[0] ?? null) === 'trabajador') {
    exit(trabajador(...array_slice($argumentos, 1)));
}

exit(coordinar(procesos: max(2, (int) (opcion($argumentos, 'procesos') ?? 8))));

/* -------------------------------------------------------------------------- */

function coordinar(int $procesos): int
{
    $pares = intdiv($procesos, 2);
    $fallos = 0;

    echo 'Base: '.BASE_ESPERADA.". Se reconstruye antes de cada corrida.\n\n";

    foreach (CORRIDAS as $escenario => $corridas) {
        foreach ($corridas as [$modo, $ritmo]) {
            reconstruirBase();
            [$restaurante, $admin, $mesas] = prepararLocal($procesos);

            if ($modo === 'sin_indices') {
                DB::statement('ALTER TABLE ocupaciones_mesa DROP INDEX uq_ocu_mesa_abierta');
                DB::statement('ALTER TABLE cuentas DROP INDEX uq_cuenta_num');
            }

            // Qué mesas pide cada proceso.
            $pedidos = match ($escenario) {
                'misma_mesa' => array_fill(0, $procesos, [$mesas[0]]),
                'cruzadas' => array_merge(...array_map(fn (int $par): array => [
                    [$mesas[2 * $par], $mesas[2 * $par + 1]],
                    [$mesas[2 * $par + 1], $mesas[2 * $par]],
                ], range(0, $pares - 1))),
                'numeracion' => array_map(fn (int $i): array => [$mesas[$i]], range(0, $procesos - 1)),
            };

            $inicio = horaDeLargada();
            $resultados = lanzar(__FILE__, array_map(fn (array $ids, int $indice): array => [
                $modo,
                (string) $admin->id,
                implode(',', $ids),
                $inicio,
                (string) (PAUSA_US + ($ritmo === 'escalonado' ? $indice * ESCALON_US : 0)),
            ], $pedidos, array_keys($pedidos)));

            $medido = medir($restaurante, $resultados);
            [$ok, $esperado] = evaluar($escenario, $modo, $ritmo, $medido, count($pedidos), $pares);

            printf("%-11s %-23s %2d procesos · cuentas: %d · máx. cuentas en una mesa: %d · números: %s\n",
                $escenario, "{$modo} ({$ritmo})", count($pedidos), $medido['cuentas'], $medido['max_por_mesa'], resumirNumeros($medido['numeros']));
            printf("%-35s abrieron: %d · 409 por la regla: %d · 409 por el índice: %d · errores de la base: %d · mesas inconsistentes: %d\n",
                '', $medido['hecho'], $medido['regla'], $medido['indice'], $medido['error'], count($medido['inconsistentes']));
            printf("%-35s esperado: %s → %s\n", '', $esperado, $ok ? 'OK' : 'FALLÓ');

            foreach (array_slice($medido['errores'], 0, 2) as $error) {
                printf("%-35s error: %s\n", '', mb_strimwidth($error, 0, 150, '…'));
            }

            echo "\n";
            $fallos += $ok ? 0 : 1;
        }
    }

    echo $fallos === 0
        ? "Todo bien: con bloqueo sale limpio; sin bloqueo los índices aguantan pero sin orden; sin índices hay interbloqueos o el dato se corrompe.\n"
        : "{$fallos} corrida(s) no salieron como se esperaba.\n";

    return $fallos === 0 ? 0 : 1;
}

/**
 * Un local con el módulo Mesas, un admin y 2·N mesas libres.
 *
 * @return array{0: Restaurante, 1: User, 2: list<int>}
 */
function prepararLocal(int $procesos): array
{
    $restaurante = new Restaurante(['nombre' => 'Local de concurrencia', 'slug' => 'local-concurrencia', 'ciudad' => 'Lima', 'estado_local' => 'abierto', 'activo' => true]);
    $restaurante->forceFill(['modulos' => ['mesas']])->save();

    $admin = new User(['name' => 'Admin', 'email' => 'admin@concurrencia.test', 'password' => 'password', 'rol' => 'admin', 'activo' => true]);
    $admin->forceFill(['restaurante_id' => $restaurante->id])->save();

    $area = new Area(['nombre' => 'Salón', 'activa' => true]);
    $area->forceFill(['restaurante_id' => $restaurante->id, 'orden' => 0])->save();

    $mesas = [];

    foreach (range(1, 2 * $procesos) as $numero) {
        $mesa = new Mesa(['codigo' => "M{$numero}", 'capacidad' => 4, 'estado' => 'disponible', 'activa' => true]);
        $mesa->forceFill(['restaurante_id' => $restaurante->id, 'area_id' => $area->id, 'orden' => $numero])->save();
        $mesas[] = $mesa->id;
    }

    return [$restaurante, $admin, $mesas];
}

/**
 * @param  list<array<string, mixed>>  $resultados
 * @return array{cuentas: int, numeros: list<int>, max_por_mesa: int, inconsistentes: list<int>, mesas_por_cuenta: list<int>, hecho: int, regla: int, indice: int, error: int, errores: list<string>}
 */
function medir(Restaurante $restaurante, array $resultados): array
{
    $cuenta = array_count_values(array_column($resultados, 'resultado'));
    $abiertas = OcupacionMesa::withoutGlobalScopes()->where('restaurante_id', $restaurante->id)->whereNull('salida_at');

    return [
        'cuentas' => Cuenta::withoutGlobalScopes()->where('restaurante_id', $restaurante->id)->count(),
        'numeros' => Cuenta::withoutGlobalScopes()->where('restaurante_id', $restaurante->id)->orderBy('numero')->pluck('numero')->all(),
        'max_por_mesa' => (int) (clone $abiertas)->selectRaw('count(*) as total')->groupBy('mesa_id')->orderByDesc('total')->value('total'),
        'inconsistentes' => GestionarCuentas::mesasInconsistentes($restaurante->id),
        'mesas_por_cuenta' => (clone $abiertas)->selectRaw('count(*) as total')->groupBy('cuenta_id')->pluck('total')->map(fn ($total): int => (int) $total)->all(),
        'hecho' => $cuenta['hecho'] ?? 0,
        'regla' => $cuenta['regla'] ?? 0,
        'indice' => $cuenta['indice'] ?? 0,
        'error' => $cuenta['error'] ?? 0,
        'errores' => array_values(array_column(array_filter($resultados, fn (array $r): bool => $r['resultado'] === 'error'), 'mensaje')),
    ];
}

/**
 * @param  array<string, mixed>  $m  lo medido
 * @return array{0: bool, 1: string} si salió como se esperaba, y qué se esperaba
 */
function evaluar(string $escenario, string $modo, string $ritmo, array $m, int $procesos, int $pares): array
{
    $ganadores = match ($escenario) {
        'misma_mesa' => 1,
        'cruzadas' => $pares,
        'numeracion' => $procesos,
    };
    $sinRepetir = count($m['numeros']) === count(array_unique($m['numeros']));

    if ($modo === 'bloqueo') {
        $limpio = $m['hecho'] === $ganadores
            && $m['regla'] === $procesos - $ganadores
            && $m['indice'] === 0
            && $m['error'] === 0
            && $m['cuentas'] === $ganadores
            && $m['numeros'] === range(1, $ganadores)
            && $m['max_por_mesa'] === 1
            && $m['inconsistentes'] === []
            && ($escenario !== 'cruzadas' || $m['mesas_por_cuenta'] === array_fill(0, $pares, 2));

        return [$limpio, "{$ganadores} cuenta(s) con números 1..{$ganadores}, el resto 409 por la regla, sin errores ni interbloqueos"];
    }

    if ($modo === 'sin_bloqueo') {
        $datoSano = $m['max_por_mesa'] <= 1 && $sinRepetir;
        $desordenado = $m['indice'] + $m['error'] >= 1;
        $pierdeLegitimas = $escenario !== 'numeracion' || $m['hecho'] < $procesos;

        return [$datoSano && $desordenado && $pierdeLegitimas, $escenario === 'numeracion'
            ? 'los índices evitan números repetidos, pero se pierden aperturas legítimas (menos de '.$procesos.' cuentas)'
            : 'los índices evitan dos cuentas en una mesa, pero los perdedores chocan con el índice o con la base, no con la regla'];
    }

    if ($escenario === 'numeracion') {
        return [! $sinRepetir, 'números de cuenta repetidos'];
    }

    if ($ritmo === 'escalonado') {
        return [$m['max_por_mesa'] > 1, 'más de una cuenta abierta en la misma mesa'];
    }

    $interbloqueos = count(array_filter($m['errores'], fn (string $error): bool => str_contains($error, 'Deadlock')));

    return [$interbloqueos >= 1, 'interbloqueos de InnoDB (error 1213) en vez de respuestas ordenadas'];
}

/**
 * @param  list<int>  $numeros
 */
function resumirNumeros(array $numeros): string
{
    if ($numeros === []) {
        return '(ninguno)';
    }

    if ($numeros === range(1, count($numeros))) {
        return count($numeros) === 1 ? '1' : '1..'.count($numeros).' sin huecos';
    }

    return implode(',', array_slice($numeros, 0, 12)).(count($numeros) > 12 ? '…' : '');
}

/**
 * Un proceso: espera la largada e intenta abrir una cuenta en sus mesas. Imprime una línea JSON.
 */
function trabajador(string $modo, string $adminId, string $mesas, string $inicio, string $pausaUs): int
{
    $admin = User::findOrFail((int) $adminId);
    Auth::setUser($admin);

    // Ensancha la ventana entre "leer" y "escribir"; la espera la fija el ritmo, no el modo.
    DB::listen(function (QueryExecuted $consulta) use ($pausaUs): void {
        $sql = strtolower($consulta->sql);

        if (str_contains($sql, 'max(`numero`)') || (str_starts_with($sql, 'select * from `mesas`') && str_contains($sql, '`id` in'))) {
            usleep((int) $pausaUs);
        }
    });

    esperarLargada($inicio);

    $gestion = app($modo === 'bloqueo' ? GestionarCuentas::class : GestionarCuentasSinBloqueo::class);

    try {
        $gestion->abrir($admin, array_map(intval(...), explode(',', $mesas)), 2);
        $resultado = ['resultado' => 'hecho', 'mensaje' => ''];
    } catch (ConflictHttpException $e) {
        $resultado = ['resultado' => $e->getMessage() === GestionarCuentas::MENSAJE_INDICE ? 'indice' : 'regla', 'mensaje' => $e->getMessage()];
    } catch (Throwable $e) {
        $resultado = ['resultado' => 'error', 'mensaje' => $e::class.': '.$e->getMessage()];
    }

    echo json_encode($resultado), "\n";

    return 0;
}
