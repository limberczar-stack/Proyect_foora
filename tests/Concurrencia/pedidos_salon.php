<?php

/**
 * Prueba de concurrencia de los pedidos de salón con procesos PHP reales contra MySQL.
 *
 * No es parte de phpunit (la suite usa SQLite en memoria). Ver tests/Concurrencia/README.md.
 *
 *   php tests/Concurrencia/pedidos_salon.php
 *   php tests/Concurrencia/pedidos_salon.php --procesos=12
 *
 * Escenarios:
 * - misma_clave:     N procesos envían la misma ronda con la misma clave       → un pedido, y todos reciben ese mismo.
 * - ronda_y_cierre:  por cada cuenta, un proceso envía una ronda y otro la cierra → nunca queda un pedido vivo
 *                    (sin servir o sin pagar) en una cuenta cerrada.
 * - numeracion:      N pedidos públicos y N rondas de salón a la vez            → números 1..2N sin huecos ni duplicados.
 * - fusion_y_mesa:   por cada par de cuentas, un proceso las fusiona y otro agrega una mesa a la que se va a
 *                    fusionar → gana uno, el otro recibe 409, y ninguna mesa queda ocupada por una cuenta fusionada.
 *
 * Modos (la base se reconstruye con migrate:fresh antes de cada corrida):
 * - bloqueo:      el código tal cual. Debe salir limpio.
 * - sin_bloqueo:  los servicios de salón sin lockForUpdate sobre el restaurante (control negativo 1).
 * - sin_indices:  además sin uq_ped_idempotencia, uq_pedido_num y uq_ped_ronda (control negativo 2), donde aplica.
 *
 * Un control negativo que NO reproduce el problema cuenta como fallo: la prueba no demostraría nada.
 * Para que la carrera sea reproducible, cada proceso espera 150 ms justo después de las lecturas que
 * deciden (la clave, el número, la cuenta, el total), en todos los modos por igual.
 *
 * Seguridad: aborta si la base de datos no se llama foora_concurrencia, porque la borra entera.
 */

use App\Models\Area;
use App\Models\CategoriaProducto;
use App\Models\Cuenta;
use App\Models\HorarioAtencion;
use App\Models\Mesa;
use App\Models\MetodoPago;
use App\Models\OcupacionMesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Restaurante;
use App\Models\User;
use App\Services\CrearPedido;
use App\Services\CrearPedidoSalon;
use App\Services\GestionarCuentas;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/_arranque.php';

const PAUSA_US = 150_000;

arrancar();

/** El mismo servicio, sin el bloqueo del restaurante. */
class CrearPedidoSalonSinBloqueo extends CrearPedidoSalon
{
    protected function bloquearRestaurante(User $actor): Restaurante
    {
        return Restaurante::findOrFail($actor->restaurante_id);
    }
}

/** El mismo servicio, sin el bloqueo del restaurante. */
class GestionarCuentasSinBloqueo extends GestionarCuentas
{
    protected function bloquearRestaurante(User $actor): Restaurante
    {
        return Restaurante::findOrFail($actor->restaurante_id);
    }
}

$argumentos = array_slice($argv, 1);

if (($argumentos[0] ?? null) === 'trabajador') {
    exit(trabajador($argumentos[1], $argumentos[2]));
}

exit(coordinar(procesos: max(2, (int) (opcion($argumentos, 'procesos') ?? 8))));

/* -------------------------------------------------------------------------- */

function coordinar(int $procesos): int
{
    $corridas = [
        'misma_clave' => ['bloqueo', 'sin_bloqueo', 'sin_indices'],
        'ronda_y_cierre' => ['bloqueo', 'sin_bloqueo'],
        'numeracion' => ['bloqueo', 'sin_indices'],
        'fusion_y_mesa' => ['bloqueo', 'sin_bloqueo'],
    ];
    $fallos = 0;

    echo 'Base: '.BASE_ESPERADA.". Se reconstruye antes de cada corrida.\n\n";

    foreach ($corridas as $escenario => $modos) {
        foreach ($modos as $modo) {
            reconstruirBase();
            $local = prepararLocal($procesos);

            if ($modo === 'sin_indices') {
                DB::statement('ALTER TABLE pedidos DROP INDEX uq_ped_idempotencia');
                DB::statement('ALTER TABLE pedidos DROP INDEX uq_pedido_num');
                // uq_ped_ronda también impide dos rondas iguales: se quita, dejando un índice simple para la FK de cuenta_id.
                DB::statement('ALTER TABLE pedidos ADD INDEX idx_tmp_cuenta (cuenta_id)');
                DB::statement('ALTER TABLE pedidos DROP INDEX uq_ped_ronda');
            }

            $trabajos = trabajos($escenario, $modo, $local, $procesos);
            $resultados = lanzar(__FILE__, array_map(fn (array $trabajo): array => [base64_encode(json_encode($trabajo)), horaDeLargada()], $trabajos));
            [$ok, $esperado, $medido] = evaluar($escenario, $modo, $local, $resultados);

            $cuenta = array_count_values(array_column($resultados, 'resultado'));
            printf("%-15s %-12s %2d procesos · hechos: %d · 409: %d · rechazos de la regla: %d · errores: %d\n",
                $escenario, $modo, count($trabajos), $cuenta['hecho'] ?? 0, $cuenta['conflicto'] ?? 0, $cuenta['rechazo'] ?? 0, $cuenta['error'] ?? 0);
            printf("%-28s medido: %s\n", '', $medido);
            printf("%-28s esperado: %s → %s\n", '', $esperado, $ok ? 'OK' : 'FALLÓ');

            foreach (array_slice(array_values(array_filter($resultados, fn (array $r): bool => $r['resultado'] === 'error')), 0, 2) as $error) {
                printf("%-28s error: %s\n", '', mb_strimwidth($error['mensaje'], 0, 150, '…'));
            }

            echo "\n";
            $fallos += $ok ? 0 : 1;
        }
    }

    echo $fallos === 0 ? "Todo bien: con bloqueo sale limpio y cada control negativo reproduce su problema.\n" : "{$fallos} corrida(s) no salieron como se esperaba.\n";

    return $fallos === 0 ? 0 : 1;
}

/**
 * Un local abierto todo el día con el módulo Mesas, un admin, un producto, efectivo y 3·N mesas libres.
 *
 * @return array{restaurante: int, admin: int, producto: int, metodo: int, mesas: list<int>}
 */
function prepararLocal(int $procesos): array
{
    $restaurante = new Restaurante(['nombre' => 'Local de concurrencia', 'slug' => 'local-concurrencia', 'ciudad' => 'Lima', 'estado_local' => 'abierto', 'activo' => true, 'visible_en_app' => true, 'acepta_recojo' => true, 'pedido_minimo' => 0]);
    $restaurante->forceFill(['modulos' => ['mesas']])->save();

    foreach (range(1, 7) as $dia) {
        $horario = new HorarioAtencion(['dia_semana' => $dia, 'abre' => '00:00', 'cierra' => '23:59', 'cerrado' => false]);
        $horario->forceFill(['restaurante_id' => $restaurante->id])->save();
    }

    $admin = new User(['name' => 'Admin', 'email' => 'admin@concurrencia.test', 'password' => 'password', 'rol' => 'admin', 'activo' => true]);
    $admin->forceFill(['restaurante_id' => $restaurante->id])->save();

    $categoria = new CategoriaProducto(['nombre' => 'Platos', 'activa' => true]);
    $categoria->forceFill(['restaurante_id' => $restaurante->id])->save();
    $producto = new Producto(['nombre' => 'Lomo saltado', 'precio' => 36.50, 'tipo' => 'simple', 'disponible' => true, 'activo' => true]);
    $producto->forceFill(['restaurante_id' => $restaurante->id, 'categoria_id' => $categoria->id])->save();

    $metodo = MetodoPago::firstOrCreate(['codigo' => 'efectivo'], ['nombre' => 'Efectivo']);
    $restaurante->metodosPago()->attach($metodo, ['activo' => true]);

    $area = new Area(['nombre' => 'Salón', 'activa' => true]);
    $area->forceFill(['restaurante_id' => $restaurante->id, 'orden' => 0])->save();

    $mesas = [];

    foreach (range(1, 3 * $procesos) as $numero) {
        $mesa = new Mesa(['codigo' => "M{$numero}", 'capacidad' => 4, 'estado' => 'disponible', 'activa' => true]);
        $mesa->forceFill(['restaurante_id' => $restaurante->id, 'area_id' => $area->id, 'orden' => $numero])->save();
        $mesas[] = $mesa->id;
    }

    return ['restaurante' => $restaurante->id, 'admin' => $admin->id, 'producto' => $producto->id, 'metodo' => $metodo->id, 'mesas' => $mesas];
}

/**
 * Qué hace cada proceso. Las cuentas se abren aquí, antes de la largada.
 *
 * @param  array{restaurante: int, admin: int, producto: int, metodo: int, mesas: list<int>}  $local
 * @return list<array<string, mixed>>
 */
function trabajos(string $escenario, string $modo, array $local, int $procesos): array
{
    $admin = User::findOrFail($local['admin']);
    Auth::setUser($admin);
    $abrir = fn (int $indice): Cuenta => app(GestionarCuentas::class)->abrir($admin, [$local['mesas'][$indice]], 2);
    $base = ['modo' => $modo, 'admin' => $local['admin'], 'producto' => $local['producto']];

    return match ($escenario) {
        'misma_clave' => array_fill(0, $procesos, [...$base, 'accion' => 'ronda', 'cuenta' => $abrir(0)->id, 'clave' => Str::uuid()->toString()]),

        'ronda_y_cierre' => array_merge(...array_map(function (int $par) use ($abrir, $base): array {
            $cuenta = $abrir($par)->id;

            return [
                [...$base, 'accion' => 'ronda', 'cuenta' => $cuenta, 'clave' => Str::uuid()->toString()],
                [...$base, 'accion' => 'cerrar', 'cuenta' => $cuenta],
            ];
        }, range(0, intdiv($procesos, 2) - 1))),

        'numeracion' => array_merge(...array_map(fn (int $i): array => [
            [...$base, 'accion' => 'ronda', 'cuenta' => $abrir($i)->id, 'clave' => Str::uuid()->toString()],
            [...$base, 'accion' => 'publico', 'metodo' => $local['metodo'], 'telefono' => '9'.str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT)],
        ], range(0, $procesos - 1))),

        'fusion_y_mesa' => array_merge(...array_map(function (int $par) use ($abrir, $base, $local, $procesos): array {
            $principal = $abrir(2 * $par)->id;
            $otra = $abrir(2 * $par + 1)->id;

            return [
                [...$base, 'accion' => 'fusionar', 'cuenta' => $principal, 'otra' => $otra],
                [...$base, 'accion' => 'agregar_mesa', 'cuenta' => $otra, 'mesa' => $local['mesas'][2 * $procesos + $par]],
            ];
        }, range(0, intdiv($procesos, 2) - 1))),
    };
}

/**
 * @param  array{restaurante: int}  $local
 * @param  list<array<string, mixed>>  $resultados
 * @return array{0: bool, 1: string, 2: string}
 */
function evaluar(string $escenario, string $modo, array $local, array $resultados): array
{
    $restaurante = $local['restaurante'];
    $pedidos = Pedido::withoutGlobalScopes()->where('restaurante_id', $restaurante);
    $cuenta = array_count_values(array_column($resultados, 'resultado'));
    $hechos = $cuenta['hecho'] ?? 0;
    $errores = $cuenta['error'] ?? 0;
    $inconsistentes = GestionarCuentas::mesasInconsistentes($restaurante);

    if ($escenario === 'misma_clave') {
        $total = (clone $pedidos)->count();
        $ids = array_unique(array_filter(array_column($resultados, 'pedido')));
        $medido = "pedidos guardados: {$total} · ids devueltos distintos: ".count($ids);

        return match ($modo) {
            'bloqueo' => [$total === 1 && $hechos === count($resultados) && count($ids) === 1, '1 pedido, y los '.count($resultados).' envíos reciben ese mismo', $medido],
            'sin_bloqueo' => [$total <= 1 && $hechos < count($resultados), 'el índice evita el duplicado, pero no todos los reenvíos reciben su pedido (409 o error)', $medido],
            default => [$total > 1, 'más de un pedido con la misma clave', $medido],
        };
    }

    if ($escenario === 'ronda_y_cierre') {
        $vivos = (clone $pedidos)
            ->whereIn('cuenta_id', Cuenta::withoutGlobalScopes()->where('restaurante_id', $restaurante)->where('estado', 'cerrada')->pluck('id'))
            ->where('estado', '!=', 'cancelado')
            ->where(fn ($query) => $query->whereIn('estado', ['en_preparacion', 'listo'])->orWhere('estado_pago', '!=', 'pagado'))
            ->count();
        $medido = "pedidos vivos en cuentas cerradas: {$vivos} · mesas inconsistentes: ".count($inconsistentes);

        return $modo === 'bloqueo'
            ? [$vivos === 0 && $errores === 0 && $inconsistentes === [] && $hechos === count($resultados) / 2, 'ningún pedido vivo en una cuenta cerrada; en cada cuenta gana uno y el otro recibe 409', $medido]
            : [$vivos > 0, 'aparece un pedido vivo en una cuenta cerrada', $medido];
    }

    if ($escenario === 'numeracion') {
        $numeros = (clone $pedidos)->orderBy('numero')->pluck('numero')->all();
        $medido = 'pedidos: '.count($numeros).' · números distintos: '.count(array_unique($numeros)).' · '.($numeros === range(1, count($numeros)) ? 'sin huecos' : 'con huecos o repetidos');

        return $modo === 'bloqueo'
            ? [$numeros === range(1, count($resultados)) && $errores === 0, 'números 1..'.count($resultados).' sin huecos ni duplicados', $medido]
            : [count($numeros) !== count(array_unique($numeros)), 'números repetidos', $medido];
    }

    // fusion_y_mesa: ninguna ocupación abierta puede pertenecer a una cuenta que ya no está abierta.
    $huerfanas = OcupacionMesa::withoutGlobalScopes()
        ->where('restaurante_id', $restaurante)
        ->whereNull('salida_at')
        ->whereHas('cuenta', fn ($query) => $query->withoutGlobalScopes()->whereNotIn('estado', Cuenta::ESTADOS_ABIERTOS))
        ->count();
    $medido = "mesas ocupadas por una cuenta fusionada: {$huerfanas} · mesas inconsistentes: ".count($inconsistentes);

    return $modo === 'bloqueo'
        ? [$huerfanas === 0 && $inconsistentes === [] && $errores === 0 && $hechos === count($resultados) / 2, 'en cada par gana uno y el otro recibe 409; ninguna mesa queda en una cuenta fusionada', $medido]
        : [$huerfanas > 0, 'una mesa queda ocupada por una cuenta ya fusionada', $medido];
}

/**
 * Un proceso: espera la largada y hace su intento. Imprime una línea JSON.
 */
function trabajador(string $codificado, string $inicio): int
{
    $t = json_decode(base64_decode($codificado), true);
    $admin = User::findOrFail($t['admin']);
    Auth::setUser($admin);
    $sinBloqueo = $t['modo'] !== 'bloqueo';

    // Ensancha la ventana entre leer y escribir, igual en todos los modos.
    DB::listen(function (QueryExecuted $consulta): void {
        $sql = strtolower($consulta->sql);

        if (str_contains($sql, '`idempotency_key` = ?')
            || str_contains($sql, 'max(`numero`)')
            || str_contains($sql, 'sum(`total`)')
            || (str_starts_with($sql, 'select * from `cuentas`') && str_contains($sql, '`cuentas`.`id` = ?'))) {
            usleep(PAUSA_US);
        }
    });

    esperarLargada($inicio);

    $cuentas = app($sinBloqueo ? GestionarCuentasSinBloqueo::class : GestionarCuentas::class);
    $linea = ['producto_id' => $t['producto'], 'variante_id' => null, 'opciones' => [], 'cantidad' => 1, 'observacion' => null];

    try {
        $pedido = match ($t['accion']) {
            'ronda' => app($sinBloqueo ? CrearPedidoSalonSinBloqueo::class : CrearPedidoSalon::class)
                ->enviar($admin, Cuenta::withoutGlobalScopes()->findOrFail($t['cuenta']), [$linea], $t['clave'])->id,
            'cerrar' => $cuentas->cerrarExterno($admin, Cuenta::withoutGlobalScopes()->findOrFail($t['cuenta']), 1, 0) && null,
            'fusionar' => $cuentas->fusionar($admin, Cuenta::withoutGlobalScopes()->findOrFail($t['cuenta']), 1, Cuenta::withoutGlobalScopes()->findOrFail($t['otra']), 1) && null,
            'agregar_mesa' => $cuentas->agregarMesa($admin, Cuenta::withoutGlobalScopes()->findOrFail($t['cuenta']), 1, $t['mesa']) && null,
            'publico' => app(CrearPedido::class)->crear(
                Restaurante::with(['horariosAtencion', 'metodosPago'])->findOrFail($admin->restaurante_id),
                ['nombre' => 'Cliente', 'telefono' => $t['telefono'], 'tipo' => 'recojo', 'direccion' => null, 'referencia' => null,
                    'metodo_pago_id' => $t['metodo'], 'referencia_pago' => null, 'idempotency_key' => Str::uuid()->toString(), 'lineas' => [$linea]],
            )->id,
        };

        $resultado = ['resultado' => 'hecho', 'pedido' => $pedido, 'mensaje' => ''];
    } catch (HttpException $e) {
        $resultado = ['resultado' => $e->getStatusCode() === 409 ? 'conflicto' : 'error', 'pedido' => null, 'mensaje' => $e->getStatusCode().': '.$e->getMessage()];
    } catch (ValidationException $e) {
        $resultado = ['resultado' => 'rechazo', 'pedido' => null, 'mensaje' => collect($e->errors())->flatten()->first()];
    } catch (Throwable $e) {
        $resultado = ['resultado' => 'error', 'pedido' => null, 'mensaje' => $e::class.': '.$e->getMessage()];
    }

    echo json_encode($resultado), "\n";

    return 0;
}
