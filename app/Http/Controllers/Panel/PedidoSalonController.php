<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cuentas\EnviarRondaRequest;
use App\Http\Requests\PedidoRequest;
use App\Http\Requests\ValidarCarritoRequest;
use App\Models\Cuenta;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Services\CalculadorLineaPedido;
use App\Services\CartaPublica;
use App\Services\CrearPedidoSalon;
use App\Services\GestionarCuentas;
use App\Services\GestionarPedidoSalon;
use App\Services\LineaPedidoCalculada;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Rondas de una cuenta de salón: tomar pedido, enviarlo, servir, anular líneas, cancelar rondas y
 * precuenta. Todo dentro del módulo Mesas (404 si está apagado). Permisos en CuentaPolicy; reglas en
 * CrearPedidoSalon y GestionarPedidoSalon.
 */
class PedidoSalonController extends Controller
{
    /**
     * Pantalla del mesero para tomar el pedido: la carta del local con búsqueda, categorías y detalle.
     */
    public function create(Request $request, Cuenta $cuenta, CartaPublica $carta): View|RedirectResponse
    {
        Gate::authorize('update', $cuenta);

        if (! $cuenta->estaAbierta()) {
            return redirect()->route('panel.mesas.index')->with('error', GestionarCuentas::MENSAJE_YA_NO_ABIERTA);
        }

        $restaurante = $request->user()->restaurante;
        $secciones = $carta->secciones($restaurante);
        $cuenta->load('ocupacionesAbiertas.mesa:id,codigo');

        return view('panel.mesas.pedido', [
            'cuenta' => $cuenta,
            'secciones' => $secciones,
            'datosSalon' => [
                'cuentaId' => $cuenta->id,
                'urlValidar' => route('panel.mesas.cuentas.pedido.validar', $cuenta),
                'urlEnviar' => route('panel.mesas.cuentas.pedido.store', $cuenta),
                'urlCuenta' => route('panel.mesas.index', ['cuenta' => $cuenta->id]),
                'lineasMaximas' => ValidarCarritoRequest::LINEAS_MAXIMAS,
                'cantidadMaxima' => CalculadorLineaPedido::CANTIDAD_MAXIMA,
                'observacionMaxima' => PedidoRequest::OBSERVACION_MAXIMA,
                'productos' => $carta->productosParaNavegador($secciones),
            ],
        ]);
    }

    /**
     * Montos oficiales del borrador, línea por línea. No guarda nada.
     */
    public function validar(EnviarRondaRequest $request, Cuenta $cuenta, CalculadorLineaPedido $calculador): JsonResponse
    {
        $resultado = $calculador->calcularCarrito($request->user()->restaurante_id, $request->lineas());

        return response()->json([
            'lineas' => array_map(fn (array $fila): array => LineaPedidoCalculada::paraNavegador($fila['linea'], $fila['errores']), $resultado['lineas']),
            'subtotal' => $resultado['subtotal'],
        ]);
    }

    public function store(EnviarRondaRequest $request, Cuenta $cuenta, CrearPedidoSalon $crear): JsonResponse
    {
        $pedido = $crear->enviar($request->user(), $cuenta, $request->lineas(), (string) $request->validated('idempotency_key'));

        return response()->json([
            'mensaje' => "Ronda {$pedido->ronda} enviada a cocina (pedido #{$pedido->numero}).",
            'pedido' => ['id' => $pedido->id, 'numero' => $pedido->numero, 'ronda' => $pedido->ronda],
            'url' => route('panel.mesas.index', ['cuenta' => $cuenta->id]),
        ]);
    }

    public function servir(Request $request, Cuenta $cuenta, Pedido $pedido, GestionarPedidoSalon $gestion): JsonResponse|RedirectResponse
    {
        Gate::authorize('servir', $cuenta);

        return $this->ejecutar($request, $cuenta, function () use ($request, $cuenta, $pedido, $gestion): string {
            $pedido = $gestion->servir($request->user(), $cuenta, $pedido);

            return "Ronda {$pedido->ronda} servida.";
        });
    }

    public function anularLinea(Request $request, Cuenta $cuenta, PedidoItem $item, GestionarPedidoSalon $gestion): JsonResponse|RedirectResponse
    {
        Gate::authorize('corregirRondas', $cuenta);

        // Las líneas no tienen scope de restaurante: se buscan por la cuenta, que sí lo tiene (404 si es ajena).
        abort_unless(Pedido::whereKey($item->pedido_id)->where('cuenta_id', $cuenta->id)->exists(), 404);

        $motivo = $this->motivo($request);

        return $this->ejecutar($request, $cuenta, function () use ($request, $cuenta, $item, $motivo, $gestion): string {
            $pedido = $gestion->anularLinea($request->user(), $cuenta, $item, $motivo);

            return $pedido->estado === 'cancelado'
                ? "Se anuló la línea. La ronda {$pedido->ronda} quedó cancelada: no le quedan productos."
                : "Se anuló la línea de la ronda {$pedido->ronda}.";
        });
    }

    public function cancelarRonda(Request $request, Cuenta $cuenta, Pedido $pedido, GestionarPedidoSalon $gestion): JsonResponse|RedirectResponse
    {
        Gate::authorize('corregirRondas', $cuenta);

        $datos = $request->validate([
            'desde' => ['required', Rule::in(['en_preparacion', 'listo'])],
        ], ['desde.*' => 'Vuelve a abrir la cuenta e inténtalo otra vez.']);
        $motivo = $this->motivo($request);

        return $this->ejecutar($request, $cuenta, function () use ($request, $cuenta, $pedido, $datos, $motivo, $gestion): string {
            $pedido = $gestion->cancelarRonda($request->user(), $cuenta, $pedido, $datos['desde'], $motivo);

            return "Se canceló la ronda {$pedido->ronda}.";
        });
    }

    public function precuenta(Request $request, Cuenta $cuenta, GestionarPedidoSalon $gestion): JsonResponse|RedirectResponse
    {
        Gate::authorize('pedirPrecuenta', $cuenta);

        $version = $request->validate(['version' => ['required', 'integer', 'min:1']], ['version.*' => 'Vuelve a abrir la cuenta e inténtalo otra vez.'])['version'];

        return $this->ejecutar($request, $cuenta, function () use ($request, $cuenta, $version, $gestion): string {
            $gestion->pedirPrecuenta($request->user(), $cuenta, (int) $version);

            return 'Precuenta pedida: caja ya tiene el aviso.';
        }, ['imprimir' => route('panel.mesas.cuentas.precuenta.imprimir', $cuenta)]);
    }

    /**
     * Vista para imprimir en 80 mm. No es comprobante de pago.
     */
    public function imprimir(Request $request, Cuenta $cuenta): View
    {
        Gate::authorize('pedirPrecuenta', $cuenta);

        $cuenta->load([
            'ocupacionesAbiertas.mesa:id,codigo',
            'mesero:id,name',
            'pedidos' => fn ($query) => $query->where('estado', '!=', 'cancelado')->with(['items' => fn ($q) => $q->vigentes()->with('opciones')]),
        ]);

        return view('panel.mesas.precuenta', [
            'cuenta' => $cuenta,
            'restaurante' => $request->user()->restaurante,
            'totalCentimos' => GestionarCuentas::totalCentimos($cuenta),
        ]);
    }

    private function motivo(Request $request): string
    {
        return $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:200']], [
            'motivo.required' => 'Escribe el motivo.',
            'motivo.min' => 'Escribe el motivo (al menos 3 caracteres).',
            'motivo.max' => 'El motivo admite hasta 200 caracteres.',
        ])['motivo'];
    }

    /**
     * @param  Closure(): string  $operacion  devuelve el mensaje de éxito
     * @param  array<string, mixed>  $extra  datos que se agregan a la respuesta JSON
     */
    private function ejecutar(Request $request, Cuenta $cuenta, Closure $operacion, array $extra = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['mensaje' => $operacion(), ...$extra]);
        }

        $destino = route('panel.mesas.cuentas.show', $cuenta);

        try {
            return redirect($destino)->with('status', $operacion());
        } catch (ConflictHttpException $conflicto) {
            return redirect($destino)->with('error', $conflicto->getMessage());
        }
    }
}
