<?php

namespace App\Support;

use App\Models\Restaurante;
use Illuminate\Support\Facades\Route;

/**
 * Módulos del panel que se encienden con foora.modulos cuando se construyan.
 *
 * Un módulo solo se muestra si está activado y su ruta existe: activarlo antes de tiempo
 * no puede dejar un enlace sin destino en el menú. Los de POR_LOCAL además los enciende
 * el superadmin local por local (restaurantes.modulos).
 */
final class Modulos
{
    /**
     * Módulo => nombre de su ruta de entrada.
     *
     * @var array<string, string>
     */
    public const RUTAS = [
        'alertas' => 'panel.alertas.index',
        'reservas' => 'panel.reservas.index',
        'mesas' => 'panel.mesas.index',
        'promociones' => 'panel.promociones.index',
        'actividad' => 'panel.actividad.index',
        'ayuda' => 'panel.ayuda',
    ];

    /**
     * Módulos que se encienden por local => qué incluyen (lo ve el superadmin).
     * "mesas" cubre también el salón de la fase B (cuentas y mesero): no se crea otra clave.
     *
     * @var array<string, array{nombre: string, descripcion: string}>
     */
    public const POR_LOCAL = [
        'mesas' => ['nombre' => 'Mesas y salón', 'descripcion' => 'Áreas y mesas del local; el mesero entra directo a su mapa de mesas.'],
    ];

    public static function activo(string $modulo): bool
    {
        return (bool) config("foora.modulos.{$modulo}", false)
            && isset(self::RUTAS[$modulo])
            && Route::has(self::RUTAS[$modulo]);
    }

    /**
     * Activo en FOORA y, si es de los que se encienden por local, encendido en este local.
     */
    public static function activoPara(string $modulo, ?Restaurante $restaurante): bool
    {
        if (! self::activo($modulo)) {
            return false;
        }

        if (! isset(self::POR_LOCAL[$modulo])) {
            return true;
        }

        return $restaurante !== null && $restaurante->tieneModulo($modulo);
    }

    /**
     * Módulos por local que el superadmin puede encender hoy (los que ya están construidos y activos).
     *
     * @return array<string, array{nombre: string, descripcion: string}>
     */
    public static function encendiblesPorLocal(): array
    {
        return array_filter(self::POR_LOCAL, fn (string $modulo): bool => self::activo($modulo), ARRAY_FILTER_USE_KEY);
    }
}
