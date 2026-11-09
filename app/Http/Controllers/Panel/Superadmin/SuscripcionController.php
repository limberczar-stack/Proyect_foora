<?php

namespace App\Http\Controllers\Panel\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Superadmin\CambiarPlanRequest;
use App\Http\Requests\Superadmin\MotivoRequest;
use App\Http\Requests\Superadmin\RegistrarPagoRequest;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\SuscripcionPago;
use App\Services\GestionarSuscripcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plan, pagos y estado de un local. Después de cada acción la página se recarga y muestra el mensaje.
 */
class SuscripcionController extends Controller
{
    public function __construct(private GestionarSuscripcion $gestion) {}

    public function plan(CambiarPlanRequest $request, Restaurante $restaurante): JsonResponse
    {
        $this->gestion->cambiarPlan($request->user(), $restaurante, (int) $request->validated('plan_id'), $request->validated('precio_pactado'));

        return $this->respuesta($request, 'Se guardó el plan y su precio pactado.');
    }

    public function registrarPago(RegistrarPagoRequest $request, Restaurante $restaurante): JsonResponse
    {
        $pago = $this->gestion->registrarPago($request->user(), $restaurante, $request->validated());
        $suscripcion = $pago->suscripcion;

        return $this->respuesta($request, $suscripcion->estado === 'activa'
            ? "Pago registrado. La suscripción vence el {$suscripcion->vence_el->format('d/m/Y')}."
            : "Pago registrado, pero su periodo ya pasó: la suscripción sigue vencida desde el {$suscripcion->vence_el->format('d/m/Y')}.");
    }

    public function anularPago(MotivoRequest $request, Restaurante $restaurante, string $pago): JsonResponse
    {
        $objetivo = SuscripcionPago::query()
            ->whereKey($pago)
            ->whereIn('suscripcion_id', Suscripcion::query()->where('restaurante_id', $restaurante->id)->select('id'))
            ->firstOrFail();

        $this->gestion->anularPago($request->user(), $restaurante, $objetivo, $request->validated('motivo'));

        return $this->respuesta($request, 'Se anuló el pago. El vencimiento y el estado volvieron a como estaban antes de ese pago.');
    }

    public function cancelar(MotivoRequest $request, Restaurante $restaurante): JsonResponse
    {
        $this->gestion->cancelar($request->user(), $restaurante, $request->validated('motivo'));

        return $this->respuesta($request, 'Se canceló la suscripción: la vitrina del local deja de estar disponible.');
    }

    public function suspender(MotivoRequest $request, Restaurante $restaurante): JsonResponse
    {
        $this->gestion->suspender($request->user(), $restaurante, $request->validated('motivo'));

        return $this->respuesta($request, 'Se suspendió el local: su vitrina ya no está disponible.');
    }

    public function reactivar(MotivoRequest $request, Restaurante $restaurante): JsonResponse
    {
        $this->gestion->reactivar($request->user(), $restaurante, $request->validated('motivo'));

        return $this->respuesta($request, 'Se reactivó el local.');
    }

    private function respuesta(Request $request, string $mensaje): JsonResponse
    {
        $request->session()->flash('status', $mensaje);

        return response()->json(['mensaje' => $mensaje]);
    }
}
