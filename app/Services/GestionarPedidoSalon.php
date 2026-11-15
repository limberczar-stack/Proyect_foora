<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\Cuenta;
use App\Models\Pedido;
use App\Models\PedidoHistorial;
use App\Models\PedidoItem;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Lo que se hace con las rondas de una cuenta de salón después de enviarlas: servir, anular una
 * línea, cancelar la ronda entera y pedir la precuenta.
 *
 * Todo con la fila del restaurante bloqueada, igual que GestionarCuentas y CrearPedidoSalon: nada
 * de esto puede cruzarse con un cierre de la cuenta. Nada se borra: una línea se anula con una
 * marca, quién y por qué, y el pedido se recalcula sin ella.
 */
class GestionarPedidoSalon
{
    public const MENSAJE_RONDA_CAMBIO = 'La ronda cambió mientras la veías. Actualizamos la cuenta.';

    public function __construct(
        private GestionarPedido $pedidos,
        private RegistrarActividad $actividad,
    ) {}

    /**
     * Listo → entregado, con la etiqueta «Servido». La regla de no entregar con pago por verificar no aplica.
     *
     * @throws ConflictHttpException si la ronda ya no está lista
     */
    public function servir(User $actor, Cuenta $cuenta, Pedido $pedido): Pedido
    {
        $this->exigirDeLaCuenta($cuenta, $pedido);

        return $this->pedidos->cambiarEstado($pedido, $actor, 'listo', 'entregado');
    }

    /**
     * Anula una línea ya enviada. Si era la última vigente, el pedido pasa a cancelado.
     */
    public function anularLinea(User $actor, Cuenta $cuenta, PedidoItem $item, string $motivo): Pedido
    {
        $motivo = self::exigirMotivo($motivo);

        return DB::transaction(function () use ($actor, $cuenta, $item, $motivo): Pedido {
            $this->bloquearRestaurante($actor);
            $cuenta = $this->cuentaAbierta($cuenta);
            $pedido = Pedido::withoutGlobalScopes()->lockForUpdate()->findOrFail($item->pedido_id);
            $this->exigirDeLaCuenta($cuenta, $pedido);
            $item = PedidoItem::query()->whereKey($item->id)->where('pedido_id', $pedido->id)->firstOrFail();

            if ($pedido->estado === 'cancelado' || $item->estaAnulada()) {
                throw new ConflictHttpException(self::MENSAJE_RONDA_CAMBIO);
            }

            $item->forceFill(['anulado_at' => now(), 'anulado_por' => $actor->id, 'motivo_anulacion' => $motivo])->save();

            $vigentes = PedidoItem::query()->where('pedido_id', $pedido->id)->vigentes()->get(['subtotal']);
            $subtotal = number_format($vigentes->sum(fn (PedidoItem $linea): int => (int) round((float) $linea->subtotal * 100)) / 100, 2, '.', '');
            $anterior = $pedido->estado;

            $pedido->forceFill(['subtotal' => $subtotal, 'total' => $subtotal]);

            if ($vigentes->isEmpty()) {
                $pedido->forceFill([
                    'estado' => 'cancelado',
                    'cancelado_at' => now(),
                    'cancelacion_motivo' => 'otro',
                    'cancelacion_detalle' => "Se anularon todas sus líneas. Última: {$motivo}",
                ]);
            }

            $pedido->save();

            $this->historial($pedido, $actor, $anterior, $pedido->estado, "Línea anulada ({$item->cantidad} × {$item->nombre}). Motivo: {$motivo}");

            $this->actividad->registrar($actor, 'mesas', 'anulo_linea',
                "Anuló {$item->cantidad} × {$item->nombre} de la ronda {$pedido->ronda} (cuenta #{$cuenta->numero}).", $cuenta,
                ['pedido' => $pedido->numero, 'linea' => $item->nombre], ['motivo' => $motivo, 'total_pedido' => $pedido->total]);

            return $pedido;
        });
    }

    /**
     * Cancela una ronda entera que aún no se sirvió. Una ronda servida se corrige anulando sus líneas.
     */
    public function cancelarRonda(User $actor, Cuenta $cuenta, Pedido $pedido, string $desde, string $motivo): Pedido
    {
        $motivo = self::exigirMotivo($motivo);
        $this->exigirDeLaCuenta($cuenta, $pedido);
        $this->cuentaAbierta($cuenta);

        $pedido = $this->pedidos->cambiarEstado($pedido, $actor, $desde, 'cancelado', ['motivo' => 'otro', 'detalle' => $motivo]);

        $this->actividad->registrar($actor, 'mesas', 'cancelo_ronda', "Canceló la ronda {$pedido->ronda} de la cuenta #{$cuenta->numero}.", $cuenta,
            ['estado' => $desde], ['estado' => 'cancelado', 'motivo' => $motivo, 'pedido' => $pedido->numero]);

        return $pedido;
    }

    /**
     * Pide la precuenta: estado precuenta, sube version y avisa a caja con la alerta precuenta_pedida.
     * Pedirla otra vez sin cambios en la cuenta no crea otra alerta ni sube la version.
     *
     * @throws ConflictHttpException si la cuenta cambió desde que se vio
     */
    public function pedirPrecuenta(User $actor, Cuenta $cuenta, int $version): Cuenta
    {
        return DB::transaction(function () use ($actor, $cuenta, $version): Cuenta {
            $this->bloquearRestaurante($actor);
            $cuenta = $this->cuentaAbierta($cuenta);

            if ($cuenta->version !== $version) {
                throw new ConflictHttpException(GestionarCuentas::MENSAJE_DESACTUALIZADA);
            }

            if ($cuenta->estado === 'precuenta') {
                return $cuenta;
            }

            $total = GestionarCuentas::totalCentimos($cuenta);

            if ($total === 0) {
                throw ValidationException::withMessages(['cuenta' => 'La cuenta no tiene nada que cobrar todavía. Envía una ronda primero.']);
            }

            $cuenta->forceFill(['estado' => 'precuenta', 'precuenta_at' => now(), 'version' => $cuenta->version + 1])->save();
            $cuenta->load('ocupacionesAbiertas.mesa:id,codigo');
            $mesas = $cuenta->mesasTexto();
            $soles = 'S/ '.number_format($total / 100, 2);

            Alerta::create([
                'restaurante_id' => $cuenta->restaurante_id,
                'tipo' => 'precuenta_pedida',
                'prioridad' => 'media',
                'titulo' => mb_substr("Precuenta pedida: {$mesas}", 0, 120),
                'mensaje' => "Cuenta #{$cuenta->numero} · {$soles}",
                'referencia_tipo' => 'cuenta',
                'referencia_id' => $cuenta->id,
            ]);

            $this->actividad->registrar($actor, 'mesas', 'pidio_precuenta', "Pidió la precuenta de la cuenta #{$cuenta->numero} ({$soles}).", $cuenta,
                ['estado' => 'abierta'], ['estado' => 'precuenta', 'total' => number_format($total / 100, 2, '.', '')]);

            return $cuenta;
        });
    }

    public static function exigirMotivo(string $motivo): string
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 200) {
            throw ValidationException::withMessages(['motivo' => 'Escribe el motivo (de 3 a 200 caracteres).']);
        }

        return $motivo;
    }

    private function bloquearRestaurante(User $actor): Restaurante
    {
        return Restaurante::lockForUpdate()->findOrFail($actor->restaurante_id);
    }

    private function cuentaAbierta(Cuenta $cuenta): Cuenta
    {
        $actual = Cuenta::withoutGlobalScopes()->findOrFail($cuenta->id);

        if (! $actual->estaAbierta()) {
            throw new ConflictHttpException(GestionarCuentas::MENSAJE_YA_NO_ABIERTA);
        }

        return $actual;
    }

    /**
     * La ronda debe ser de esta cuenta (una de otra cuenta del mismo local, o una ronda que se movió al
     * fusionar, es un 409: la pantalla está desactualizada).
     */
    private function exigirDeLaCuenta(Cuenta $cuenta, Pedido $pedido): void
    {
        if (! $pedido->esSalon() || $pedido->cuenta_id !== $cuenta->id) {
            throw new ConflictHttpException(self::MENSAJE_RONDA_CAMBIO);
        }
    }

    private function historial(Pedido $pedido, User $actor, string $anterior, string $nuevo, string $nota): void
    {
        PedidoHistorial::create([
            'pedido_id' => $pedido->id,
            'estado_anterior' => $anterior,
            'estado_nuevo' => $nuevo,
            'user_id' => $actor->id,
            'nota' => mb_substr($nota, 0, 255),
        ]);
    }
}
