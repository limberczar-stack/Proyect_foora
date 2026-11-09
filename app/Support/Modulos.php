<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Módulos del panel que se encienden con foora.modulos cuando se construyan.
 *
 * Un módulo solo se muestra si está activado y su ruta existe: activarlo antes de tiempo
 * no puede dejar un enlace sin destino en el menú.
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

    public static function activo(string $modulo): bool
    {
        return (bool) config("foora.modulos.{$modulo}", false)
            && isset(self::RUTAS[$modulo])
            && Route::has(self::RUTAS[$modulo]);
    }
}
