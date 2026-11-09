<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\CambiarEstadoPedidoRequest;
use App\Http\Requests\Panel\RevisarPagoRequest;
use App\Models\Pedido;
use App\Models\User;
use App\Policies\PedidoPolicy;
use App\Services\GestionarPedido;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PedidoController extends Controller
{
    private const POR_PAGINA = 20;

    private const NOVEDADES_MAXIMAS = 20;

    /** Pestañas en orden. Entregados y cancelados solo muestran los de hoy. */
    private const PESTANAS = [
        'nuevo' => 'Nuevos',
        'en_preparacion' => 'En preparación',
        'listo' => 'Listos',
        'en_camino' => 'En camino',
        'entregado' => 'Entregados hoy',
        'cancelado' => 'Cancelados hoy',
    ];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Pedido::class);

        /** @var User $usuario */
        $usuario = $request->user();
        $pestanas = $this->pestanasDe($usuario);

        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in(array_keys($pestanas))],
            'tipo' => ['nullable', Rule::in(['delivery', 'recojo'])],
            'q' => ['nullable', 'string', 'max:60'],
            'pedido' => ['nullable', 'integer'],
        ]);

        $estado = $filtros['estado'] ?? array_key_first($pestanas);
        $tipo = $filtros['tipo'] ?? null;
        $busqueda = trim((string) ($filtros['q'] ?? ''));
        $inicioHoy = $this->inicioDeHoy($usuario);

        $pedidos = $this->base($usuario)
            ->with('metodoPago')
            ->where('estado', $estado)
            ->when(in_array($estado, ['entregado', 'cancelado'], true), fn (Builder $query) => $query->where($estado === 'entregado' ? 'entregado_at' : 'cancelado_at', '>=', $inicioHoy))
            ->when($tipo, fn (Builder $query) => $query->where('tipo', $tipo))
            ->when($busqueda !== '', fn (Builder $query) => $this->buscar($query, $busqueda))
            ->orderBy('created_at', in_array($estado, ['entregado', 'cancelado'], true) ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        $datos = [
            'pedidos' => $pedidos,
            'pestanas' => $pestanas,
            'contadores' => $this->contadores($usuario, $tipo),
            'estado' => $estado,
            'tipo' => $tipo,
            'busqueda' => $busqueda,
            'zonaHoraria' => $usuario->restaurante->zona_horaria,
        ];

        if ($request->ajax()) {
            return view('panel.pedidos._lista', $datos);
        }

        $seleccionado = isset($filtros['pedido']) ? $this->base($usuario)->find($filtros['pedido']) : null;

        return view('panel.pedidos.index', [
            ...$datos,
            'seleccionado' => $seleccionado && $usuario->can('view', $seleccionado) ? $this->paraDetalle($seleccionado) : null,
            'ultimoId' => (int) $this->base($usuario)->max('id'),
            'sonido' => (bool) $usuario->restaurante->sonido_nuevos_pedidos,
        ]);
    }

    /**
     * Detalle como fragmento HTML (AJAX). Sin AJAX lleva a la lista con el pedido abierto.
     */
    public function show(Request $request, Pedido $pedido): View|RedirectResponse
    {
        Gate::authorize('view', $pedido);

        if (! $request->ajax()) {
            return redirect()->route('panel.pedidos.index', ['estado' => $pedido->estado, 'pedido' => $pedido->id]);
        }

        return view('panel.pedidos._detalle', ['pedido' => $this->paraDetalle($pedido)]);
    }

    public function estado(CambiarEstadoPedidoRequest $request, Pedido $pedido, GestionarPedido $gestion): JsonResponse|RedirectResponse
    {
        $destino = $request->validated('destino');
        $actualizado = $gestion->cambiarEstado($pedido, $request->user(), $request->validated('desde'), $destino, $request->datos());

        $mensaje = "Pedido #{$actualizado->numero}: ".match ($destino) {
            'en_preparacion' => 'aceptado.',
            'listo' => 'listo.',
            'en_camino' => 'en camino.',
            'entregado' => $actualizado->tipo === 'recojo' ? 'recogido.' : 'entregado.',
            default => 'cancelado.',
        };

        return $this->responder($request, $mensaje, $actualizado);
    }

    public function pago(RevisarPagoRequest $request, Pedido $pedido, GestionarPedido $gestion): JsonResponse|RedirectResponse
    {
        $valido = $request->validated('accion') === 'validar';
        $actualizado = $gestion->revisarPago($pedido, $request->user(), $valido);

        $mensaje = $valido
            ? "Pago del pedido #{$actualizado->numero} validado."
            : "Pago del pedido #{$actualizado->numero} rechazado. Si no se cobrará, cancela el pedido con el motivo «Pago no confirmado».";

        return $this->responder($request, $mensaje, $actualizado);
    }

    /**
     * Consulta periódica: contadores, badge y pedidos llegados después del último visto.
     */
    public function novedades(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Pedido::class);

        /** @var User $usuario */
        $usuario = $request->user();
        $datos = $request->validate([
            'desde' => ['required', 'integer', 'min:0'],
            'tipo' => ['nullable', Rule::in(['delivery', 'recojo'])],
        ]);

        $nuevos = $this->base($usuario)
            ->where('id', '>', $datos['desde'])
            ->orderBy('id')
            ->limit(self::NOVEDADES_MAXIMAS)
            ->get(['id', 'numero', 'nombre_cliente', 'tipo', 'estado', 'total']);

        return response()->json([
            'contadores' => $this->contadores($usuario, $datos['tipo'] ?? null),
            'badge' => $this->base($usuario)->where('estado', 'nuevo')->count(),
            'ultimo_id' => (int) ($nuevos->max('id') ?? $datos['desde']),
            'nuevos' => $nuevos->map(fn (Pedido $pedido): array => [
                'id' => $pedido->id,
                'numero' => $pedido->numero,
                'cliente' => $pedido->primerNombre(),
                'tipo' => $pedido->tipoTexto(),
                'estado' => Pedido::ESTADOS[$pedido->estado],
                'total' => $pedido->total,
            ])->all(),
        ]);
    }

    private function responder(Request $request, string $mensaje, Pedido $pedido): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['mensaje' => $mensaje, 'estado' => $pedido->estado, 'estado_pago' => $pedido->estado_pago]);
        }

        return redirect()->route('panel.pedidos.index', ['estado' => $pedido->estado, 'pedido' => $pedido->id])->with('status', $mensaje);
    }

    private function paraDetalle(Pedido $pedido): Pedido
    {
        return $pedido->load(['items.opciones', 'historial.user', 'metodoPago', 'restaurante']);
    }

    /**
     * Pedidos del restaurante del usuario. Además del scope global, el filtro es explícito, y la
     * cocina solo alcanza lo que está en preparación o listo.
     *
     * @return Builder<Pedido>
     */
    private function base(User $usuario): Builder
    {
        return Pedido::query()
            ->where('pedidos.restaurante_id', $usuario->restaurante_id)
            ->when($usuario->rol === 'cocina', fn (Builder $query) => $query->whereIn('estado', PedidoPolicy::ESTADOS_COCINA));
    }

    /**
     * @return array<string, string>
     */
    private function pestanasDe(User $usuario): array
    {
        return $usuario->rol === 'cocina'
            ? array_intersect_key(self::PESTANAS, array_flip(PedidoPolicy::ESTADOS_COCINA))
            : self::PESTANAS;
    }

    /**
     * Una sola consulta para todas las pestañas.
     *
     * @return array<string, int>
     */
    private function contadores(User $usuario, ?string $tipo): array
    {
        $inicioHoy = $this->inicioDeHoy($usuario);

        $conteo = $this->base($usuario)
            ->when($tipo, fn (Builder $query) => $query->where('tipo', $tipo))
            ->where(function (Builder $query) use ($inicioHoy): void {
                $query->whereIn('estado', ['nuevo', 'en_preparacion', 'listo', 'en_camino'])
                    ->orWhere(fn (Builder $q) => $q->where('estado', 'entregado')->where('entregado_at', '>=', $inicioHoy))
                    ->orWhere(fn (Builder $q) => $q->where('estado', 'cancelado')->where('cancelado_at', '>=', $inicioHoy));
            })
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return collect($this->pestanasDe($usuario))->map(fn (string $texto, string $clave): int => (int) ($conteo[$clave] ?? 0))->all();
    }

    /**
     * Medianoche de hoy en la zona horaria del restaurante.
     */
    private function inicioDeHoy(User $usuario): CarbonInterface
    {
        return $usuario->restaurante->inicioDeHoy();
    }

    /**
     * @param  Builder<Pedido>  $query
     */
    private function buscar(Builder $query, string $busqueda): void
    {
        $termino = '%'.addcslashes($busqueda, '%_\\').'%';
        $digitos = ltrim($busqueda, '#');

        $query->where(function (Builder $query) use ($termino, $digitos): void {
            $query->where('nombre_cliente', 'like', $termino)->orWhere('telefono_cliente', 'like', $termino);

            if (ctype_digit($digitos)) {
                $query->orWhere('numero', (int) $digitos);
            }
        });
    }
}
