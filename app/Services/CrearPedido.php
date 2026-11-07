<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\PedidoHistorial;
use App\Models\PedidoItem;
use App\Models\PedidoItemOpcion;
use App\Models\Restaurante;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crea un pedido de la vitrina pública. Los montos siempre salen de CalculadorLineaPedido.
 *
 * Todo ocurre en una transacción que bloquea la fila del restaurante: así los pedidos de un mismo
 * local se atienden de uno en uno, y el correlativo, la idempotencia y el límite por teléfono
 * no se pueden saltar con envíos simultáneos.
 */
class CrearPedido
{
    private const INTENTOS_POR_BLOQUEO_MUTUO = 3;

    public function __construct(private CalculadorLineaPedido $calculador) {}

    /**
     * Si la clave de idempotencia ya existe en el restaurante, devuelve ese mismo pedido.
     *
     * @param  Restaurante  $restaurante  con horariosAtencion y metodosPago cargados
     * @param  array{nombre: string, telefono: string, tipo: string, direccion: ?string, referencia: ?string, metodo_pago_id: int, referencia_pago: ?string, idempotency_key: string, lineas: list<array{producto_id: int, variante_id: ?int, opciones: list<int>, cantidad: int, observacion: ?string}>}  $datos
     *
     * @throws ValidationException
     */
    public function crear(Restaurante $restaurante, array $datos): Pedido
    {
        return DB::transaction(function () use ($restaurante, $datos): Pedido {
            $local = Restaurante::whereKey($restaurante->id)->lockForUpdate()->firstOrFail()->setRelations($restaurante->getRelations());

            $existente = $this->pedidos($local)->where('idempotency_key', $datos['idempotency_key'])->first();

            if ($existente) {
                return $existente;
            }

            $this->comprobarLocal($local, $datos['tipo']);
            $metodo = $this->metodoPago($local, (int) $datos['metodo_pago_id']);
            $this->comprobarPedidosActivos($local, $datos['telefono']);

            $carrito = $this->calculador->calcularCarrito($local->id, $datos['lineas']);
            $this->comprobarCarrito($local, $carrito);

            $pedido = $this->guardarPedido($local, $metodo, $datos, $carrito['subtotal']);
            $this->guardarItems($pedido, $carrito['lineas'], $datos['lineas']);
            $this->registrarInicio($pedido, $metodo);

            return $pedido;
        }, self::INTENTOS_POR_BLOQUEO_MUTUO);
    }

    private function pedidos(Restaurante $local): Builder
    {
        return Pedido::withoutGlobalScope(Pedido::RESTAURANTE_SCOPE)->where('restaurante_id', $local->id);
    }

    private function comprobarLocal(Restaurante $local, string $tipo): void
    {
        if (! $local->abiertoAhora()) {
            throw ValidationException::withMessages(['restaurante' => "{$local->nombre} está cerrado ahora. Tu carrito sigue guardado para cuando abra."]);
        }

        $aceptado = $tipo === 'delivery' ? $local->acepta_delivery : $local->acepta_recojo;

        if (! $aceptado) {
            throw ValidationException::withMessages(['tipo' => $tipo === 'delivery' ? 'Este local no hace delivery por ahora.' : 'Este local no atiende recojo por ahora.']);
        }
    }

    private function metodoPago(Restaurante $local, int $metodoPagoId): MetodoPago
    {
        $metodo = $local->metodosPagoDisponibles()->firstWhere('id', $metodoPagoId);

        if (! $metodo) {
            throw ValidationException::withMessages(['metodo_pago_id' => 'Ese método de pago no está disponible en este local.']);
        }

        return $metodo;
    }

    private function comprobarPedidosActivos(Restaurante $local, string $telefono): void
    {
        $activos = $this->pedidos($local)->where('telefono_cliente', $telefono)->activos()->count();

        if ($activos >= Pedido::MAX_ACTIVOS_POR_TELEFONO) {
            throw ValidationException::withMessages(['telefono' => 'Ya tienes '.Pedido::MAX_ACTIVOS_POR_TELEFONO.' pedidos en curso en este local. Podrás hacer otro cuando se entregue alguno.']);
        }
    }

    /**
     * @param  array{lineas: list<array{linea: ?LineaPedidoCalculada, errores: list<string>}>, subtotal: string}  $carrito
     */
    private function comprobarCarrito(Restaurante $local, array $carrito): void
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

        if ($this->aCentimos($carrito['subtotal']) < $this->aCentimos($local->pedido_minimo)) {
            throw ValidationException::withMessages(['lineas' => "El pedido mínimo en este local es S/ {$local->pedido_minimo}."]);
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function guardarPedido(Restaurante $local, MetodoPago $metodo, array $datos, string $subtotal): Pedido
    {
        $esDelivery = $datos['tipo'] === 'delivery';
        $costoDelivery = $esDelivery ? $local->costo_delivery : '0.00';

        // Un pago por verificar nunca se acepta solo: el local no debe cocinar sin confirmarlo.
        $aceptarSolo = $local->acepta_pedidos_auto && ! $metodo->requiere_validacion;

        return Pedido::create([
            'restaurante_id' => $local->id,
            'numero' => (int) $this->pedidos($local)->max('numero') + 1,
            'codigo_seguimiento' => Str::random(Pedido::LONGITUD_CODIGO),
            'idempotency_key' => $datos['idempotency_key'],
            'nombre_cliente' => $datos['nombre'],
            'telefono_cliente' => $datos['telefono'],
            'tipo' => $datos['tipo'],
            'origen' => 'app',
            'direccion_entrega' => $esDelivery ? $datos['direccion'] : null,
            'referencia_entrega' => $esDelivery ? $datos['referencia'] : null,
            'metodo_pago_id' => $metodo->id,
            'estado_pago' => $metodo->requiere_validacion ? 'por_verificar' : 'pendiente',
            'referencia_pago' => $metodo->requiere_validacion ? $datos['referencia_pago'] : null,
            'subtotal' => $subtotal,
            'descuento' => 0,
            'costo_delivery' => $costoDelivery,
            'total' => $this->aSoles($this->aCentimos($subtotal) + $this->aCentimos($costoDelivery)),
            'estado' => $aceptarSolo ? 'en_preparacion' : 'nuevo',
            'aceptado_at' => $aceptarSolo ? now() : null,
            'tiempo_estimado_min' => $local->tiempo_prep_min,
            'consentimiento_at' => now(),
            'consentimiento_version' => Pedido::CONSENTIMIENTO_VERSION,
        ]);
    }

    /**
     * @param  list<array{linea: ?LineaPedidoCalculada, errores: list<string>}>  $calculadas
     * @param  list<array{observacion: ?string}>  $lineas
     */
    private function guardarItems(Pedido $pedido, array $calculadas, array $lineas): void
    {
        foreach ($calculadas as $indice => $fila) {
            /** @var LineaPedidoCalculada $linea */
            $linea = $fila['linea'];

            $item = PedidoItem::create([
                'pedido_id' => $pedido->id,
                'producto_id' => $linea->productoId,
                'variante_id' => $linea->varianteId,
                'variante_nombre' => $linea->varianteNombre,
                'nombre' => $linea->nombre,
                'cantidad' => $linea->cantidad,
                'precio_unitario' => $linea->precioUnitario,
                'subtotal' => $linea->subtotal,
                'observacion' => $lineas[$indice]['observacion'] ?? null,
            ]);

            foreach ($linea->opciones as $opcion) {
                PedidoItemOpcion::create(['pedido_item_id' => $item->id, ...$opcion]);
            }
        }
    }

    private function registrarInicio(Pedido $pedido, MetodoPago $metodo): void
    {
        PedidoHistorial::create([
            'pedido_id' => $pedido->id,
            'estado_anterior' => null,
            'estado_nuevo' => $pedido->estado,
            'nota' => $pedido->estado === 'nuevo' ? 'Pedido recibido desde la vitrina.' : 'Pedido aceptado automáticamente.',
        ]);

        if ($pedido->estado_pago === 'por_verificar') {
            $this->alertar($pedido, 'pago_pendiente', 'urgente', "Pago por validar: pedido #{$pedido->numero}", "{$metodo->nombre} por S/ {$pedido->total}.");
        }

        if ($pedido->estado === 'nuevo') {
            $this->alertar($pedido, 'pedido_nuevo', 'media', "Nuevo pedido #{$pedido->numero}", "Total S/ {$pedido->total}.");
        }
    }

    private function alertar(Pedido $pedido, string $tipo, string $prioridad, string $titulo, string $mensaje): void
    {
        Alerta::create([
            'restaurante_id' => $pedido->restaurante_id,
            'tipo' => $tipo,
            'prioridad' => $prioridad,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'referencia_tipo' => 'pedido',
            'referencia_id' => $pedido->id,
        ]);
    }

    private function aCentimos(string|float $monto): int
    {
        return (int) round((float) $monto * 100);
    }

    private function aSoles(int $centimos): string
    {
        return number_format($centimos / 100, 2, '.', '');
    }
}
