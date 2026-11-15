<?php

namespace App\Services;

use App\Http\Middleware\EnsureLocalOperativo;
use App\Models\Alerta;
use App\Models\Cuenta;
use App\Models\Pedido;
use App\Models\PedidoHistorial;
use App\Models\Restaurante;
use App\Models\User;
use App\Support\Modulos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Envía una ronda de una cuenta de salón a la cocina: un pedido tipo salon, origen local.
 *
 * Una transacción con la fila del restaurante bloqueada, la misma que usan CrearPedido y
 * GestionarCuentas: el número del pedido sale de la misma secuencia que los de la vitrina, la
 * ronda no se repite en la cuenta y nunca entra una ronda en una cuenta que se está cerrando.
 *
 * - Los montos salen de CalculadorLineaPedido; del navegador solo llegan ids y cantidades.
 * - Entra directo a en_preparacion: no hay nada que aceptar ni pago que verificar.
 * - No exige local abierto ni pedido mínimo, y no crea la alerta pedido_nuevo.
 * - La clave de idempotencia repite el mismo pedido si se reenvía (el wifi falla y el mesero toca dos veces).
 * - Una ronda no sube cuentas.version. Si la cuenta estaba en precuenta, vuelve a abierta y su alerta se resuelve.
 */
class CrearPedidoSalon
{
    public const MENSAJE_CLAVE_AJENA = 'Ese envío ya se usó en otro pedido. Vuelve a abrir la cuenta e inténtalo otra vez.';

    public const MENSAJE_INDICE = 'Otra persona envió una ronda al mismo tiempo. Actualizamos la cuenta; vuelve a enviarla.';

    public function __construct(
        private CalculadorLineaPedido $calculador,
        private RegistrarActividad $actividad,
    ) {}

    /**
     * @param  list<array{producto_id: int, variante_id: ?int, opciones: list<int>, cantidad: int, observacion: ?string}>  $lineas
     *
     * @throws ConflictHttpException si la cuenta ya no está abierta, la clave es de otro pedido o el local no opera
     * @throws ValidationException con la clave lineas.{n} de cada línea que no se puede pedir
     */
    public function enviar(User $actor, Cuenta $cuenta, array $lineas, string $claveIdempotencia): Pedido
    {
        try {
            return DB::transaction(function () use ($actor, $cuenta, $lineas, $claveIdempotencia): Pedido {
                $local = $this->bloquearRestaurante($actor);

                $existente = $this->pedidos($local)->where('idempotency_key', $claveIdempotencia)->first();

                if ($existente !== null) {
                    return $this->repetido($existente, $cuenta);
                }

                $this->exigirLocal($local);
                $cuenta = $this->cuentaAbierta($local, $cuenta);

                $carrito = $this->calculador->calcularCarrito($local->id, $lineas);
                $this->exigirLineasValidas($carrito);

                $pedido = $this->guardarPedido($local, $cuenta, $actor, $claveIdempotencia, $carrito['subtotal']);
                CrearPedido::guardarItems($pedido, $carrito['lineas'], $lineas);

                PedidoHistorial::create([
                    'pedido_id' => $pedido->id,
                    'estado_anterior' => null,
                    'estado_nuevo' => 'en_preparacion',
                    'user_id' => $actor->id,
                    'nota' => "Ronda {$pedido->ronda} de la cuenta #{$cuenta->numero}.",
                ]);

                $this->volverDePrecuenta($cuenta);

                $this->actividad->registrar($actor, 'mesas', 'envio_ronda',
                    "Envió la ronda {$pedido->ronda} de la cuenta #{$cuenta->numero} (pedido #{$pedido->numero}).", $cuenta,
                    null, ['pedido' => $pedido->numero, 'ronda' => $pedido->ronda, 'lineas' => count($lineas)]);

                return $pedido;
            });
        } catch (UniqueConstraintViolationException) {
            // Con el local bloqueado no debería pasar: si pasa, que se reintente en vez de un 500.
            throw new ConflictHttpException(self::MENSAJE_INDICE);
        }
    }

    /**
     * El bloqueo que ordena los pedidos del local. Es protected solo para que el control negativo
     * de tests/Concurrencia/pedidos_salon.php ejecute este mismo código sin él.
     */
    protected function bloquearRestaurante(User $actor): Restaurante
    {
        return Restaurante::lockForUpdate()->findOrFail($actor->restaurante_id);
    }

    /**
     * @return Builder<Pedido>
     */
    private function pedidos(Restaurante $local): Builder
    {
        return Pedido::withoutGlobalScope(Pedido::RESTAURANTE_SCOPE)->where('restaurante_id', $local->id);
    }

    /**
     * El reenvío de una ronda ya guardada devuelve la misma ronda. Una clave usada en otra cuenta
     * o en un pedido de la vitrina no se acepta.
     */
    private function repetido(Pedido $existente, Cuenta $cuenta): Pedido
    {
        if (! $existente->esSalon() || $existente->cuenta_id !== $cuenta->id) {
            throw new ConflictHttpException(self::MENSAJE_CLAVE_AJENA);
        }

        return $existente;
    }

    private function exigirLocal(Restaurante $local): void
    {
        if (! Modulos::activoPara('mesas', $local)) {
            throw new NotFoundHttpException;
        }

        if (! $local->operativo()) {
            throw new ConflictHttpException(EnsureLocalOperativo::MENSAJE);
        }
    }

    private function cuentaAbierta(Restaurante $local, Cuenta $cuenta): Cuenta
    {
        $actual = Cuenta::withoutGlobalScopes()->where('restaurante_id', $local->id)->findOrFail($cuenta->id);

        if (! $actual->estaAbierta()) {
            throw new ConflictHttpException(GestionarCuentas::MENSAJE_YA_NO_ABIERTA);
        }

        return $actual;
    }

    /**
     * @param  array{lineas: list<array{linea: ?LineaPedidoCalculada, errores: list<string>}>, subtotal: string}  $carrito
     */
    private function exigirLineasValidas(array $carrito): void
    {
        $errores = [];

        foreach ($carrito['lineas'] as $indice => $fila) {
            if ($fila['errores'] !== []) {
                $errores["lineas.{$indice}"] = $fila['errores'];
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    private function guardarPedido(Restaurante $local, Cuenta $cuenta, User $actor, string $clave, string $subtotal): Pedido
    {
        $ronda = (int) Pedido::withoutGlobalScopes()->where('cuenta_id', $cuenta->id)->max('ronda') + 1;
        $ahora = now();

        $pedido = new Pedido;
        $pedido->forceFill([
            'restaurante_id' => $local->id,
            'numero' => (int) $this->pedidos($local)->max('numero') + 1,
            'codigo_seguimiento' => Str::random(Pedido::LONGITUD_CODIGO),
            'idempotency_key' => $clave,
            'tipo' => 'salon',
            'origen' => 'local',
            'cuenta_id' => $cuenta->id,
            'ronda' => $ronda,
            'creado_por' => $actor->id,
            'estado_pago' => 'pendiente',
            'subtotal' => $subtotal,
            'descuento' => 0,
            'costo_delivery' => 0,
            'total' => $subtotal,
            'estado' => 'en_preparacion',
            'aceptado_at' => $ahora,
            'tiempo_estimado_min' => $local->tiempo_prep_min,
        ])->save();

        return $pedido;
    }

    /**
     * Una ronda nueva sobre una cuenta en precuenta la devuelve a abierta: la precuenta impresa ya no
     * vale. Su alerta se resuelve; la próxima precuenta crea otra.
     */
    private function volverDePrecuenta(Cuenta $cuenta): void
    {
        if ($cuenta->estado !== 'precuenta') {
            return;
        }

        $cuenta->forceFill(['estado' => 'abierta'])->save();
        self::resolverAlertaPrecuenta($cuenta);
    }

    public static function resolverAlertaPrecuenta(Cuenta $cuenta): void
    {
        Alerta::withoutGlobalScope(Alerta::RESTAURANTE_SCOPE)
            ->where('restaurante_id', $cuenta->restaurante_id)
            ->where('tipo', 'precuenta_pedida')
            ->where('referencia_tipo', 'cuenta')
            ->where('referencia_id', $cuenta->id)
            ->whereNull('resuelta_at')
            ->update(['resuelta_at' => now(), 'leida' => true, 'leida_at' => now()]);
    }
}
