<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mesas\AreaRequest;
use App\Models\Area;
use App\Services\GestionarMesas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Áreas del salón (módulo Mesas). Solo el admin, según AreaPolicy y MesaPolicy.
 */
class AreaController extends Controller
{
    public function __construct(private GestionarMesas $gestion) {}

    public function store(AreaRequest $request): JsonResponse|RedirectResponse
    {
        $area = $this->gestion->crearArea($request->user(), ['nombre' => $request->validated('nombre')]);

        return $this->responder($request, "Se creó el área «{$area->nombre}».", $area->id);
    }

    public function update(AreaRequest $request, Area $area): JsonResponse|RedirectResponse
    {
        $area = $this->gestion->actualizarArea($request->user(), $area, ['nombre' => $request->validated('nombre')]);

        return $this->responder($request, "Se guardaron los cambios de «{$area->nombre}».", $area->id);
    }

    public function activa(Request $request, Area $area): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $area);

        $activa = $request->validate(['activa' => ['required', 'boolean']], [
            'activa.required' => 'Indica si el área queda activa.',
            'activa.boolean' => 'Indica si el área queda activa.',
        ])['activa'];

        $area = $this->gestion->cambiarActivaArea($request->user(), $area, filter_var($activa, FILTER_VALIDATE_BOOLEAN));

        return $this->responder($request, $area->activa
            ? "«{$area->nombre}» está activa."
            : "«{$area->nombre}» quedó desactivada: cajeros y meseros no la ven. Sus mesas activas siguen contando para tu plan.", $area->id);
    }

    public function mover(Request $request, Area $area): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $area);

        $direccion = $request->validate(['direccion' => ['required', Rule::in(['subir', 'bajar'])]])['direccion'];
        $this->gestion->moverArea($request->user(), $area, $direccion);

        return $this->responder($request, "Se movió «{$area->nombre}».", $area->id);
    }

    public function destroy(Request $request, Area $area): JsonResponse|RedirectResponse
    {
        Gate::authorize('delete', $area);

        $this->gestion->eliminarArea($request->user(), $area);

        return $this->responder($request, "Se eliminó el área «{$area->nombre}».", null);
    }

    private function responder(Request $request, string $mensaje, ?int $areaId): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['mensaje' => $mensaje]);
        }

        return redirect()->route('panel.mesas.index', array_filter(['editar' => 1, 'area' => $areaId]))->with('status', $mensaje);
    }
}
