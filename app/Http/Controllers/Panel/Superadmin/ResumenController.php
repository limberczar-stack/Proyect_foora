<?php

namespace App\Http\Controllers\Panel\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\SuscripcionPago;
use Illuminate\View\View;

/**
 * Inicio del superadmin: cómo está la cartera de locales hoy.
 */
class ResumenController extends Controller
{
    private const ULTIMOS_PAGOS = 10;

    /**
     * Los conteos se calculan local por local porque cada uno compara con la fecha de su zona horaria.
     */
    public function __invoke(): View
    {
        $restaurantes = Restaurante::query()->with('suscripcionActual')->get();

        $vigentes = $restaurantes->reject(fn (Restaurante $restaurante): bool => $restaurante->estaSuspendido());
        $conEstado = fn (string $estado) => $vigentes->filter(fn (Restaurante $restaurante): bool => $restaurante->estadoSuscripcion() === $estado);

        return view('panel.superadmin.resumen', [
            'activos' => $conEstado('activa')->count(),
            'enPrueba' => $conEstado('prueba')->count(),
            'porVencer' => $vigentes->filter(function (Restaurante $restaurante): bool {
                $dias = $restaurante->diasParaVencer();

                return $dias !== null && $dias >= 0 && $dias <= Suscripcion::DIAS_AVISO && $restaurante->suscripcionActual->estado !== 'cancelada';
            })->count(),
            'vencidos' => $conEstado('vencida')->count(),
            'sinSuscripcion' => $restaurantes->filter(fn (Restaurante $restaurante): bool => $restaurante->suscripcionActual === null)->count(),
            'suspendidos' => $restaurantes->count() - $vigentes->count(),
            'ingresoMensual' => $conEstado('activa')->sum(fn (Restaurante $restaurante): float => (float) $restaurante->suscripcionActual->precio_pactado),
            'ultimosPagos' => SuscripcionPago::query()
                ->with(['suscripcion' => fn ($query) => $query->with('restaurante:id,nombre')])
                ->latest('id')
                ->limit(self::ULTIMOS_PAGOS)
                ->get(),
        ]);
    }
}
