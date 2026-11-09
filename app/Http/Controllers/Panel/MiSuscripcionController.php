<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Restaurante;
use App\Models\SuscripcionPago;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * El admin del local consulta su suscripción. Solo lectura: el plan, el estado y el vencimiento
 * los cambia el equipo de FOORA.
 */
class MiSuscripcionController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $restaurante = $usuario->restaurante;

        Gate::authorize('configurar', $restaurante ?? new Restaurante);

        $suscripcion = $restaurante->suscripcionActual?->load('plan');

        return view('panel.mi-suscripcion', [
            'restaurante' => $restaurante,
            'suscripcion' => $suscripcion,
            'pagos' => $suscripcion === null
                ? collect()
                : SuscripcionPago::query()->where('suscripcion_id', $suscripcion->id)->vigentes()->orderByDesc('id')->get(),
        ]);
    }
}
