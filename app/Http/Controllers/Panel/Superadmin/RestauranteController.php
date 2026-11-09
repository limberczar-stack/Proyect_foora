<?php

namespace App\Http\Controllers\Panel\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Superadmin\AltaRestauranteRequest;
use App\Http\Requests\Superadmin\DatosRestauranteRequest;
use App\Http\Requests\Superadmin\NotasInternasRequest;
use App\Models\ActividadLog;
use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\Rubro;
use App\Models\Suscripcion;
use App\Models\SuscripcionPago;
use App\Services\CrearRestaurante;
use App\Services\RegistrarActividad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Locales vistos por el superadmin: listado, alta, detalle y edición.
 * El acceso lo controla el middleware can:superadmin de las rutas.
 */
class RestauranteController extends Controller
{
    private const POR_PAGINA = 20;

    private const HISTORIAL_MAXIMO = 30;

    /** @var array<string, string> */
    public const FILTROS_ESTADO = [
        'activos' => 'No suspendidos',
        'suspendidos' => 'Suspendidos',
        'prueba' => 'En prueba',
        'activa' => 'Suscripción activa',
        'vencida' => 'Vencidos',
        'cancelada' => 'Cancelados',
        'sin_suscripcion' => 'Sin suscripción',
    ];

    public function index(Request $request): View
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'estado' => ['nullable', Rule::in(array_keys(self::FILTROS_ESTADO))],
            'plan' => ['nullable', 'integer'],
            'ciudad' => ['nullable', 'string', 'max:80'],
        ]);

        $busqueda = trim((string) ($filtros['q'] ?? ''));

        $restaurantes = Restaurante::query()
            ->with(['rubro:id,nombre', 'plan:id,nombre', 'suscripcionActual'])
            ->withCount(['pedidos as pedidos_mes' => fn (Builder $query) => $query->where('pedidos.created_at', '>=', $this->inicioDeMes())])
            ->when($busqueda !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($busqueda): void {
                $patron = '%'.addcslashes($busqueda, '%_\\').'%';
                $query->where('nombre', 'like', $patron)->orWhere('slug', 'like', $patron)->orWhere('ciudad', 'like', $patron);
            }))
            ->when($filtros['plan'] ?? null, fn (Builder $query, $plan) => $query->where('plan_id', $plan))
            ->when($filtros['ciudad'] ?? null, fn (Builder $query, $ciudad) => $query->where('ciudad', $ciudad))
            ->when($filtros['estado'] ?? null, fn (Builder $query, string $estado) => $this->filtrarPorEstado($query, $estado))
            ->orderBy('nombre')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return view('panel.superadmin.restaurantes.index', [
            'restaurantes' => $restaurantes,
            'filtros' => [...$filtros, 'q' => $busqueda],
            'planes' => Plan::orderBy('precio_mensual')->get(['id', 'nombre']),
            'ciudades' => Restaurante::query()->distinct()->orderBy('ciudad')->pluck('ciudad'),
            'estados' => self::FILTROS_ESTADO,
        ]);
    }

    public function create(): View
    {
        return view('panel.superadmin.restaurantes.crear', [
            'rubros' => Rubro::orderBy('nombre')->get(['id', 'nombre']),
            'planes' => Plan::where('activo', true)->orderBy('precio_mensual')->get(),
        ]);
    }

    /**
     * La contraseña temporal viaja solo en esta respuesta: no se guarda en sesión, logs ni auditoría.
     */
    public function store(AltaRestauranteRequest $request, CrearRestaurante $crear): JsonResponse
    {
        [$restaurante, $admin, $temporal] = $crear->crear($request->user(), $request->validated());

        return response()->json([
            'mensaje' => "Se dio de alta {$restaurante->nombre}.",
            'url_detalle' => route('panel.superadmin.restaurantes.show', $restaurante),
            'admin' => ['name' => $admin->name, 'email' => $admin->email],
            'password_temporal' => $temporal,
        ], 201)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function show(Restaurante $restaurante): View
    {
        $restaurante->load(['rubro:id,nombre', 'plan', 'suscripcionActual.plan']);

        $suscripciones = Suscripcion::query()
            ->where('restaurante_id', $restaurante->id)
            ->with(['plan:id,nombre'])
            ->orderByDesc('id')
            ->get();

        $pagos = SuscripcionPago::query()
            ->whereIn('suscripcion_id', $suscripciones->pluck('id'))
            ->with('registradoPor:id,name')
            ->orderByDesc('id')
            ->get();

        return view('panel.superadmin.restaurantes.show', [
            'restaurante' => $restaurante,
            'suscripciones' => $suscripciones,
            'pagos' => $pagos,
            'pagoAnulable' => $pagos->first(fn (SuscripcionPago $pago): bool => ! $pago->estaAnulado() && $pago->suscripcion_id === $restaurante->suscripcionActual?->id),
            'personal' => $restaurante->users()->orderByRaw("CASE WHEN rol = 'admin' THEN 0 ELSE 1 END")->orderBy('name')->get(['id', 'name', 'email', 'rol', 'activo']),
            'pedidosMes' => $restaurante->pedidos()->where('created_at', '>=', $this->inicioDeMes())->count(),
            'historial' => ActividadLog::query()
                ->where('restaurante_id', $restaurante->id)
                ->where('modulo', 'suscripciones')
                ->with('user:id,name')
                ->latest('id')
                ->limit(self::HISTORIAL_MAXIMO)
                ->get(),
            'rubros' => Rubro::orderBy('nombre')->get(['id', 'nombre']),
            'planes' => Plan::where('activo', true)->orderBy('precio_mensual')->get(),
            'metodos' => SuscripcionPago::METODOS,
        ]);
    }

    public function update(DatosRestauranteRequest $request, Restaurante $restaurante, RegistrarActividad $actividad): JsonResponse
    {
        $campos = ['nombre', 'rubro_id', 'ciudad', 'telefono', 'direccion'];
        $antes = $restaurante->only($campos);

        $restaurante->fill($request->safe()->only($campos))->save();

        [$cambiosAntes, $cambiosDespues] = RegistrarActividad::diferencias($antes, $restaurante->only($campos));

        if ($cambiosDespues !== []) {
            $actividad->registrar($request->user(), 'suscripciones', 'edito_local', 'Editó los datos del local.', $restaurante, $cambiosAntes, $cambiosDespues, $restaurante);
        }

        return $this->respuesta($request, 'Se guardaron los datos del local.');
    }

    /**
     * El contenido de las notas no pasa a la auditoría: el local podrá consultar su actividad.
     */
    public function notas(NotasInternasRequest $request, Restaurante $restaurante, RegistrarActividad $actividad): JsonResponse
    {
        $restaurante->forceFill(['notas_internas' => $request->validated('notas_internas')])->save();

        if ($restaurante->wasChanged('notas_internas')) {
            $actividad->registrar($request->user(), 'suscripciones', 'edito_notas', 'Editó las notas internas.', $restaurante, restaurante: $restaurante);
        }

        return $this->respuesta($request, 'Se guardaron las notas internas.');
    }

    /**
     * La página se recarga después de guardar: el mensaje se muestra ahí.
     */
    private function respuesta(Request $request, string $mensaje): JsonResponse
    {
        $request->session()->flash('status', $mensaje);

        return response()->json(['mensaje' => $mensaje]);
    }

    /**
     * @param  Builder<Restaurante>  $query
     */
    private function filtrarPorEstado(Builder $query, string $estado): void
    {
        $hoy = Carbon::now(Restaurante::ZONA_POR_DEFECTO)->toDateString();

        match ($estado) {
            'activos' => $query->where('activo', true),
            'suspendidos' => $query->where('activo', false),
            'sin_suscripcion' => $query->whereDoesntHave('suscripciones'),
            'cancelada' => $query->whereHas('suscripcionActual', fn (Builder $q) => $q->where('estado', 'cancelada')),
            'vencida' => $query->whereHas('suscripcionActual', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('estado', 'vencida')
                ->orWhere(fn (Builder $q) => $q->whereIn('estado', ['prueba', 'activa'])->whereDate('vence_el', '<', $hoy)))),
            default => $query->whereHas('suscripcionActual', fn (Builder $q) => $q->where('estado', $estado)->whereDate('vence_el', '>=', $hoy)),
        };
    }

    /**
     * Inicio del mes en Perú, en la zona en que se guardan las fechas.
     */
    private function inicioDeMes(): Carbon
    {
        return Carbon::now(Restaurante::ZONA_POR_DEFECTO)->startOfMonth()->setTimezone(config('app.timezone'));
    }
}
