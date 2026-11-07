<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\EstadoLocalRequest;
use App\Models\Pedido;
use App\Models\Restaurante;
use App\Services\RegistrarActividad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interruptor "Abierto / Cerrado" de la barra lateral (admin y cajero).
 *
 * Cerrar exige confirmación: sin `confirmar`, responde 409 con los pedidos activos
 * para que el panel los muestre en el modal antes de cerrar de verdad.
 */
class EstadoLocalController extends Controller
{
    public function update(EstadoLocalRequest $request, RegistrarActividad $actividad): JsonResponse|RedirectResponse
    {
        /** @var Restaurante $restaurante */
        $restaurante = $request->user()->restaurante;
        $destino = $request->validated('estado');
        $anterior = $restaurante->estado_local;

        if ($destino === 'cerrado' && $anterior === 'abierto' && ! $request->validated('confirmar')) {
            $activos = $this->pedidosActivos($restaurante);

            return response()->json([
                'requiere_confirmacion' => true,
                'pedidos_activos' => $activos,
                'message' => 'Confirma que quieres cerrar el local.',
            ], Response::HTTP_CONFLICT);
        }

        if ($anterior !== $destino) {
            $restaurante->forceFill(['estado_local' => $destino])->save();

            $actividad->registrar($request->user(), 'configuracion', 'cambio_estado_local',
                $destino === 'abierto' ? 'Abrió el local.' : 'Cerró el local.', $restaurante,
                ['estado_local' => $anterior], ['estado_local' => $destino]);
        }

        $mensaje = $destino === 'abierto'
            ? 'El local está abierto: tus clientes ya pueden pedir.'
            : 'El local está cerrado: tus clientes no podrán hacer pedidos nuevos.';

        if ($request->expectsJson()) {
            return response()->json([
                'estado' => $destino,
                'abierto' => $destino === 'abierto',
                'mensaje' => $mensaje,
            ]);
        }

        return back()->with('status', $mensaje);
    }

    private function pedidosActivos(Restaurante $restaurante): int
    {
        return Pedido::query()->where('restaurante_id', $restaurante->id)->activos()->count();
    }
}
