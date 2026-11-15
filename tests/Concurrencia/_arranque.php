<?php

/**
 * Arranque común de los scripts de tests/Concurrencia: apunta Laravel a la base desechable,
 * se niega a correr en cualquier otra y lanza procesos PHP reales que arrancan a la vez.
 *
 * Cada script hace `require __DIR__.'/_arranque.php';` y luego `arrancar();`.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

const BASE_ESPERADA = 'foora_concurrencia';

/**
 * Arranca Laravel contra foora_concurrencia. Aborta (código 2) si no puede garantizar esa base.
 */
function arrancar(): void
{
    $raiz = dirname(__DIR__, 2);

    if (file_exists($raiz.'/bootstrap/cache/config.php')) {
        fwrite(STDERR, "La configuración está en caché (bootstrap/cache/config.php): no se puede cambiar de base. Ejecuta php artisan config:clear.\n");
        exit(2);
    }

    // Antes de arrancar Laravel: el .env no pisa variables que ya existen.
    foreach (['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => BASE_ESPERADA] as $clave => $valor) {
        putenv("{$clave}={$valor}");
        $_ENV[$clave] = $_SERVER[$clave] = $valor;
    }

    require $raiz.'/vendor/autoload.php';
    $app = require $raiz.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    exigirBaseDeConcurrencia();
}

function exigirBaseDeConcurrencia(): void
{
    $conexion = DB::connection();

    if ($conexion->getDriverName() !== 'mysql' || $conexion->getDatabaseName() !== BASE_ESPERADA) {
        fwrite(STDERR, sprintf("Abortado: la base es «%s» (%s) y debe ser «%s» en MySQL. Este script la borra entera.\n",
            $conexion->getDatabaseName(), $conexion->getDriverName(), BASE_ESPERADA));
        exit(2);
    }

    try {
        $conexion->getPdo();
    } catch (Throwable $e) {
        fwrite(STDERR, 'No se pudo conectar a '.BASE_ESPERADA.'. ¿Creaste la base? CREATE DATABASE '.BASE_ESPERADA.";\n".$e->getMessage()."\n");
        exit(2);
    }
}

/**
 * Borra y vuelve a crear todas las tablas. Vuelve a comprobar la base justo antes de hacerlo.
 */
function reconstruirBase(): void
{
    exigirBaseDeConcurrencia();
    Artisan::call('migrate:fresh', ['--force' => true]);
}

/**
 * @param  list<string>  $argumentos
 */
function opcion(array $argumentos, string $nombre): ?string
{
    foreach ($argumentos as $argumento) {
        if (str_starts_with($argumento, "--{$nombre}=")) {
            return substr($argumento, strlen($nombre) + 3);
        }
    }

    return null;
}

/**
 * Lanza un proceso `php $script trabajador ...$args` por cada trabajo y espera a todos.
 * Cada trabajador debe imprimir una línea JSON al final; si no lo hace, cuenta como error.
 *
 * @param  list<list<string>>  $trabajos  argumentos de cada trabajador
 * @return list<array<string, mixed>>
 */
function lanzar(string $script, array $trabajos): array
{
    $hijos = [];

    foreach ($trabajos as $argumentos) {
        $proceso = proc_open([PHP_BINARY, $script, 'trabajador', ...$argumentos], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
        $hijos[] = [$proceso, $tuberias];
    }

    $resultados = [];

    foreach ($hijos as [$proceso, $tuberias]) {
        $salida = stream_get_contents($tuberias[1]);
        $errores = stream_get_contents($tuberias[2]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        proc_close($proceso);

        $resultado = json_decode(trim((string) strrchr("\n".trim($salida), "\n")), true);
        $resultados[] = is_array($resultado) ? $resultado : ['resultado' => 'error', 'mensaje' => trim($salida."\n".$errores) ?: 'sin salida'];
    }

    return $resultados;
}

/**
 * El trabajador abre su conexión y espera hasta la hora de largada, para que todos empiecen a la vez.
 */
function esperarLargada(string $inicio): void
{
    DB::connection()->getPdo();
    $espera = (float) $inicio - microtime(true);

    if ($espera > 0) {
        usleep((int) ($espera * 1_000_000));
    }
}

function horaDeLargada(float $segundos = 4.0): string
{
    return sprintf('%.6F', microtime(true) + $segundos);
}
