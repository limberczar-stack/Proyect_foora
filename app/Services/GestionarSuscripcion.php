<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\SuscripcionPago;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lo que el superadmin hace sobre la suscripción y el estado de un local. Cada cambio queda en
 * actividad_log. Las escrituras sobre la suscripción van con la fila bloqueada.
 */
class GestionarSuscripcion
{
    public function __construct(private RegistrarActividad $actividad) {}

    /**
     * Un pago lleva vence_el hasta el fin de su periodo (nunca hacia atrás) y activa la suscripción.
     * Si el periodo entero ya pasó, sigue vencida: activarla daría un estado falso.
     *
     * @param  array{monto: string|float, metodo: string, referencia: ?string, periodo_desde: string, periodo_hasta: string}  $datos
     */
    public function registrarPago(User $actor, Restaurante $restaurante, array $datos): SuscripcionPago
    {
        return DB::transaction(function () use ($actor, $restaurante, $datos): SuscripcionPago {
            $suscripcion = $this->suscripcionBloqueada($restaurante)
                ?? throw ValidationException::withMessages(['pago' => 'Este local no tiene suscripción. Asígnale un plan primero.']);

            $this->rechazarDuplicado($restaurante, $suscripcion, $datos);

            $antes = $this->foto($suscripcion);
            $venceActual = $suscripcion->vence_el->toDateString();
            $venceNuevo = max($venceActual, $datos['periodo_hasta']);
            $sinPrecio = (float) $suscripcion->precio_pactado === 0.0;

            $pago = SuscripcionPago::create([
                'suscripcion_id' => $suscripcion->id,
                'monto' => $datos['monto'],
                'metodo' => $datos['metodo'],
                'referencia' => $datos['referencia'] ?? null,
                'periodo_desde' => $datos['periodo_desde'],
                'periodo_hasta' => $datos['periodo_hasta'],
                'pagado_at' => now(),
                'registrado_por' => $actor->id,
                'vence_antes' => $venceActual,
                'estado_antes' => $suscripcion->estado,
                'precio_antes' => $sinPrecio ? $suscripcion->precio_pactado : null,
            ]);

            $suscripcion->forceFill([
                'vence_el' => $venceNuevo,
                'estado' => $venceNuevo >= $restaurante->fechaHoy() ? 'activa' : 'vencida',
                // Al salir de la prueba (precio 0) empieza a pagar el precio de su plan.
                'precio_pactado' => $sinPrecio ? ($suscripcion->plan?->precio_mensual ?? $datos['monto']) : $suscripcion->precio_pactado,
            ])->save();

            $this->actividad->registrar($actor, 'suscripciones', 'registro_pago',
                'Registró un pago de S/ '.number_format((float) $datos['monto'], 2).'.', $pago,
                $antes,
                [...$this->foto($suscripcion), 'monto' => $pago->monto, 'metodo' => $pago->metodo, 'referencia' => $pago->referencia],
                $restaurante);

            return $pago;
        });
    }

    /**
     * Anula con una marca, nunca con DELETE. Solo el último pago vigente: así vence_el y el estado
     * vuelven a lo que había antes de ese pago sin rehacer la historia.
     */
    public function anularPago(User $actor, Restaurante $restaurante, SuscripcionPago $pago, string $motivo): SuscripcionPago
    {
        return DB::transaction(function () use ($actor, $restaurante, $pago, $motivo): SuscripcionPago {
            $suscripcion = $this->suscripcionBloqueada($restaurante);
            $pago->refresh();

            if ($suscripcion === null || $pago->suscripcion_id !== $suscripcion->id) {
                throw ValidationException::withMessages(['motivo' => 'Este pago es de una suscripción anterior y ya no se puede anular.']);
            }

            if ($pago->estaAnulado()) {
                throw ValidationException::withMessages(['motivo' => 'Este pago ya estaba anulado.']);
            }

            $ultimoId = SuscripcionPago::where('suscripcion_id', $suscripcion->id)->vigentes()->max('id');

            if ($pago->id !== (int) $ultimoId) {
                throw ValidationException::withMessages(['motivo' => 'Solo se puede anular el último pago vigente. Anula primero los posteriores.']);
            }

            $antes = $this->foto($suscripcion);

            $pago->forceFill(['anulado_at' => now(), 'anulado_por' => $actor->id, 'motivo_anulacion' => $motivo])->save();

            $vence = $pago->vence_antes?->toDateString() ?? $suscripcion->vence_el->toDateString();

            $suscripcion->forceFill([
                'vence_el' => $vence,
                'estado' => $this->estadoTrasAnular($restaurante, $suscripcion, $pago, $vence),
                'precio_pactado' => $pago->precio_antes ?? $suscripcion->precio_pactado,
            ])->save();

            $this->actividad->registrar($actor, 'suscripciones', 'anulo_pago',
                'Anuló un pago de S/ '.number_format((float) $pago->monto, 2).". Motivo: {$motivo}", $pago,
                $antes,
                [...$this->foto($suscripcion), 'motivo' => $motivo],
                $restaurante);

            return $pago;
        });
    }

    /**
     * Cambia el plan del local y de su suscripción, conservando vencimiento y estado.
     * Un local sin suscripción empieza una prueba.
     */
    public function cambiarPlan(User $actor, Restaurante $restaurante, int $planId, string|float $precioPactado): void
    {
        DB::transaction(function () use ($actor, $restaurante, $planId, $precioPactado): void {
            $suscripcion = $this->suscripcionBloqueada($restaurante);
            $antes = ['plan_id' => $restaurante->plan_id, ...($suscripcion ? $this->foto($suscripcion) : [])];

            if ($suscripcion === null) {
                $hoy = Carbon::parse($restaurante->fechaHoy(), 'UTC');
                $suscripcion = Suscripcion::create([
                    'restaurante_id' => $restaurante->id,
                    'plan_id' => $planId,
                    'estado' => 'prueba',
                    'inicia_el' => $hoy->toDateString(),
                    'vence_el' => $hoy->copy()->addDays(Suscripcion::DIAS_PRUEBA)->toDateString(),
                    'precio_pactado' => $precioPactado,
                ]);
            } else {
                $suscripcion->forceFill(['plan_id' => $planId, 'precio_pactado' => $precioPactado])->save();
            }

            $restaurante->forceFill(['plan_id' => $planId])->save();
            $restaurante->unsetRelation('plan')->unsetRelation('suscripcionActual');

            [$cambiosAntes, $cambiosDespues] = RegistrarActividad::diferencias($antes, ['plan_id' => $planId, ...$this->foto($suscripcion->refresh())]);

            if ($cambiosDespues !== []) {
                $plan = Plan::find($planId);
                $this->actividad->registrar($actor, 'suscripciones', 'cambio_plan',
                    "Cambió el plan a {$plan?->nombre} con precio pactado de S/ ".number_format((float) $precioPactado, 2).'.', $suscripcion,
                    $cambiosAntes, $cambiosDespues, $restaurante);
            }
        });
    }

    public function cancelar(User $actor, Restaurante $restaurante, string $motivo): void
    {
        DB::transaction(function () use ($actor, $restaurante, $motivo): void {
            $suscripcion = $this->suscripcionBloqueada($restaurante)
                ?? throw ValidationException::withMessages(['motivo' => 'Este local no tiene suscripción.']);

            if ($suscripcion->estado === 'cancelada') {
                throw ValidationException::withMessages(['motivo' => 'La suscripción ya estaba cancelada.']);
            }

            $antes = $this->foto($suscripcion);
            $suscripcion->forceFill(['estado' => 'cancelada'])->save();
            $restaurante->unsetRelation('suscripcionActual');

            $this->actividad->registrar($actor, 'suscripciones', 'cancelo_suscripcion', "Canceló la suscripción. Motivo: {$motivo}", $suscripcion,
                $antes, [...$this->foto($suscripcion), 'motivo' => $motivo], $restaurante);
        });
    }

    public function suspender(User $actor, Restaurante $restaurante, string $motivo): void
    {
        $this->cambiarActivo($actor, $restaurante, false, $motivo);
    }

    public function reactivar(User $actor, Restaurante $restaurante, string $motivo): void
    {
        $this->cambiarActivo($actor, $restaurante, true, $motivo);
    }

    private function cambiarActivo(User $actor, Restaurante $restaurante, bool $activo, string $motivo): void
    {
        DB::transaction(function () use ($actor, $restaurante, $activo, $motivo): void {
            $local = Restaurante::whereKey($restaurante->id)->lockForUpdate()->firstOrFail();

            if ($local->activo === $activo) {
                throw ValidationException::withMessages(['motivo' => $activo ? 'El local ya estaba activo.' : 'El local ya estaba suspendido.']);
            }

            $local->forceFill(['activo' => $activo])->save();
            $restaurante->activo = $activo;

            $this->actividad->registrar($actor, 'suscripciones', $activo ? 'reactivo_local' : 'suspendio_local',
                ($activo ? 'Reactivó' : 'Suspendió')." el local. Motivo: {$motivo}", $local,
                ['activo' => ! $activo], ['activo' => $activo, 'motivo' => $motivo], $restaurante);
        });
    }

    private function suscripcionBloqueada(Restaurante $restaurante): ?Suscripcion
    {
        return Suscripcion::withoutGlobalScope(Suscripcion::RESTAURANTE_SCOPE)
            ->where('restaurante_id', $restaurante->id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Doble envío o el mismo comprobante dos veces: se compara con los pagos vigentes del local.
     * Sin referencia, un pago igual (monto y periodo) se trata como repetido.
     *
     * @param  array{monto: string|float, referencia: ?string, periodo_desde: string, periodo_hasta: string}  $datos
     */
    private function rechazarDuplicado(Restaurante $restaurante, Suscripcion $suscripcion, array $datos): void
    {
        $pagos = SuscripcionPago::query()
            ->vigentes()
            ->whereIn('suscripcion_id', Suscripcion::withoutGlobalScope(Suscripcion::RESTAURANTE_SCOPE)->where('restaurante_id', $restaurante->id)->select('id'));

        if (filled($datos['referencia'] ?? null)) {
            if ((clone $pagos)->whereRaw('LOWER(referencia) = ?', [mb_strtolower($datos['referencia'])])->exists()) {
                throw ValidationException::withMessages(['referencia' => 'Ya hay un pago registrado con esta referencia.']);
            }

            return;
        }

        $igual = (clone $pagos)
            ->where('suscripcion_id', $suscripcion->id)
            ->whereNull('referencia')
            ->where('monto', $datos['monto'])
            ->whereDate('periodo_desde', $datos['periodo_desde'])
            ->whereDate('periodo_hasta', $datos['periodo_hasta'])
            ->exists();

        if ($igual) {
            throw ValidationException::withMessages(['pago' => 'Ya hay un pago igual registrado, con el mismo monto y periodo.']);
        }
    }

    /**
     * El estado sale de las fechas y de lo que había antes del pago: una suscripción cancelada vuelve
     * a cancelada, una ya vencida a vencida; y con el vencimiento aún vigente, a como estaba.
     */
    private function estadoTrasAnular(Restaurante $restaurante, Suscripcion $suscripcion, SuscripcionPago $pago, string $vence): string
    {
        if ($pago->estado_antes === 'cancelada') {
            return 'cancelada';
        }

        if ($vence < $restaurante->fechaHoy()) {
            return 'vencida';
        }

        if (in_array($pago->estado_antes, ['prueba', 'activa'], true)) {
            return $pago->estado_antes;
        }

        return SuscripcionPago::where('suscripcion_id', $suscripcion->id)->vigentes()->exists() ? 'activa' : 'prueba';
    }

    /**
     * @return array{estado: string, vence_el: string, precio_pactado: string, plan_id: int}
     */
    private function foto(Suscripcion $suscripcion): array
    {
        return [
            'estado' => $suscripcion->estado,
            'vence_el' => $suscripcion->vence_el->toDateString(),
            'precio_pactado' => number_format((float) $suscripcion->precio_pactado, 2, '.', ''),
            'plan_id' => $suscripcion->plan_id,
        ];
    }
}
