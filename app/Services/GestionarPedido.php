<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\Pedido;
use App\Models\PedidoHistorial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Cambios del panel sobre un pedido: estado y revisión de pago.
 *
 * Cada cambio bloquea la fila del pedido y compara con lo que veía quien actúa. Si otra persona
 * se adelantó, el segundo cambio falla con 409 en vez de pisar al primero.
 */
class GestionarPedido
{
    public const MENSAJE_DESACTUALIZADO = 'El pedido ya cambió de estado. Actualizamos la vista.';

    /**
     * @param  array{motivo?: ?string, detalle?: ?string, minutos?: ?int, cobrado?: bool}  $datos
     *
     * @throws ConflictHttpException si el estado ya no es $desde
     * @throws ValidationException si la transición no está permitida
     */
    public function cambiarEstado(Pedido $pedido, User $usuario, string $desde, string $destino, array $datos = []): Pedido
    {
        return DB::transaction(function () use ($pedido, $usuario, $desde, $destino, $datos): Pedido {
            $actual = $this->bloquear($pedido);

            if ($actual->estado !== $desde) {
                throw new ConflictHttpException(self::MENSAJE_DESACTUALIZADO);
            }

            if (! $actual->puedePasarA($destino)) {
                throw ValidationException::withMessages(['destino' => $this->motivoNoPermitido($actual, $destino)]);
            }

            $nota = $this->aplicar($actual, $usuario, $destino, $datos);
            $actual->estado = $destino;
            $actual->save();

            $this->historial($actual, $usuario, $desde, $destino, $nota);
            $this->resolverAlertas($actual, match ($destino) {
                'cancelado' => null,
                'en_preparacion' => ['pedido_nuevo'],
                default => [],
            });

            return $actual;
        });
    }

    /**
     * Valida o rechaza un pago por verificar (Yape o Plin). Rechazar no cancela el pedido.
     *
     * @throws ConflictHttpException si el pago ya fue revisado
     */
    public function revisarPago(Pedido $pedido, User $usuario, bool $valido): Pedido
    {
        return DB::transaction(function () use ($pedido, $usuario, $valido): Pedido {
            $actual = $this->bloquear($pedido);

            if ($actual->estado_pago !== 'por_verificar' || $actual->estado === 'cancelado') {
                throw new ConflictHttpException('El pago de este pedido ya fue revisado. Actualizamos la vista.');
            }

            $actual->forceFill([
                'estado_pago' => $valido ? 'pagado' : 'rechazado',
                'pago_validado_por' => $usuario->id,
                'pago_validado_at' => now(),
            ])->save();

            $this->historial($actual, $usuario, $actual->estado, $actual->estado, $valido ? 'Pago validado.' : 'Pago rechazado.');
            $this->resolverAlertas($actual, ['pago_pendiente']);

            return $actual;
        });
    }

    private function bloquear(Pedido $pedido): Pedido
    {
        return Pedido::withoutGlobalScope(Pedido::RESTAURANTE_SCOPE)->whereKey($pedido->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Fechas y datos que trae cada transición. Devuelve la nota del historial.
     *
     * @param  array{motivo?: ?string, detalle?: ?string, minutos?: ?int, cobrado?: bool}  $datos
     */
    private function aplicar(Pedido $pedido, User $usuario, string $destino, array $datos): ?string
    {
        $ahora = now();

        switch ($destino) {
            case 'en_preparacion':
                $pedido->aceptado_at = $ahora;

                return null;

            case 'listo':
                $pedido->listo_at = $ahora;

                if ($pedido->tipo === 'recojo') {
                    $pedido->recoger_antes_de = $ahora->copy()->addMinutes(Pedido::MINUTOS_PARA_RECOGER);
                }

                return null;

            case 'en_camino':
                $minutos = (int) ($datos['minutos'] ?? Pedido::MINUTOS_ETA_POR_DEFECTO);
                $pedido->salida_at = $ahora;
                $pedido->eta_at = $ahora->copy()->addMinutes($minutos);

                return "Llega en unos {$minutos} min.";

            case 'entregado':
                return $this->entregar($pedido, $usuario, (bool) ($datos['cobrado'] ?? false));

            case 'cancelado':
                $motivo = $datos['motivo'] ?? 'otro';
                $pedido->forceFill([
                    'cancelado_at' => $ahora,
                    'cancelacion_motivo' => $motivo,
                    'cancelacion_detalle' => $datos['detalle'] ?? null,
                ]);

                return 'Motivo: '.Pedido::MOTIVOS_CANCELACION[$motivo].'.';
        }

        return null;
    }

    private function entregar(Pedido $pedido, User $usuario, bool $cobrado): ?string
    {
        if ($pedido->estado_pago === 'por_verificar') {
            throw ValidationException::withMessages(['cobrado' => 'Valida o rechaza el pago antes de entregar el pedido.']);
        }

        if ($pedido->estado_pago === 'rechazado' && ! $cobrado) {
            throw ValidationException::withMessages(['cobrado' => 'El pago fue rechazado. Marca «Cobrado» si cobraste de otra forma.']);
        }

        $pedido->entregado_at = now();

        if ($cobrado && $pedido->estado_pago !== 'pagado') {
            $pedido->forceFill(['estado_pago' => 'pagado', 'pago_validado_por' => $usuario->id, 'pago_validado_at' => now()]);

            return 'Cobrado al entregar.';
        }

        return null;
    }

    private function historial(Pedido $pedido, User $usuario, string $anterior, string $nuevo, ?string $nota): void
    {
        PedidoHistorial::create([
            'pedido_id' => $pedido->id,
            'estado_anterior' => $anterior,
            'estado_nuevo' => $nuevo,
            'user_id' => $usuario->id,
            'nota' => $nota,
        ]);
    }

    /**
     * @param  list<string>|null  $tipos  null resuelve todas las alertas del pedido
     */
    private function resolverAlertas(Pedido $pedido, ?array $tipos): void
    {
        if ($tipos === []) {
            return;
        }

        Alerta::withoutGlobalScope(Alerta::RESTAURANTE_SCOPE)
            ->where('restaurante_id', $pedido->restaurante_id)
            ->where('referencia_tipo', 'pedido')
            ->where('referencia_id', $pedido->id)
            ->whereNull('resuelta_at')
            ->when($tipos !== null, fn ($query) => $query->whereIn('tipo', $tipos))
            ->update(['resuelta_at' => now(), 'leida' => true, 'leida_at' => now()]);
    }

    private function motivoNoPermitido(Pedido $pedido, string $destino): string
    {
        $desde = Pedido::ESTADOS[$pedido->estado] ?? $pedido->estado;
        $hacia = Pedido::ESTADOS[$destino] ?? $destino;

        return match (true) {
            $destino === 'en_camino' && $pedido->tipo !== 'delivery' => 'Un pedido de recojo no sale en camino: márcalo como recogido.',
            $destino === 'entregado' && $pedido->estado === 'listo' && $pedido->tipo === 'delivery' => 'Un delivery debe salir en camino antes de entregarse.',
            default => "No se puede pasar un pedido de «{$desde}» a «{$hacia}».",
        };
    }
}
