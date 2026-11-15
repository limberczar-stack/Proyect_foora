<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mesas\AgregarMesasRequest;
use App\Http\Requests\Mesas\MesaRequest;
use App\Models\Area;
use App\Models\Cuenta;
use App\Models\Mesa;
use App\Models\Restaurante;
use App\Models\User;
use App\Services\GestionarMesas;
use App\Services\PlanoMesas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Mapa de mesas del local (módulo Mesas). Las rutas responden 404 si el módulo está apagado
 * (middleware modulo:mesas); los permisos por rol viven en MesaPolicy y las reglas en GestionarMesas.
 */
class MesaController extends Controller
{
    public function __construct(private GestionarMesas $gestion) {}

    /**
     * El plano para atender: áreas y mesas activas con sus cuentas, igual para admin, cajero y mesero.
     * Con ?editar=1 el admin pasa a la gestión del salón, donde ve también lo desactivado (atenuado).
     */
    public function index(Request $request, PlanoMesas $plano): View
    {
        Gate::authorize('viewAny', Mesa::class);

        /** @var User $actor */
        $actor = $request->user();
        $restaurante = $actor->restaurante->loadMissing('plan');
        $gestiona = $actor->can('gestionar', Mesa::class);

        if ($gestiona && $request->boolean('editar')) {
            return $this->gestion($request, $restaurante);
        }

        $datos = $plano->para($restaurante);

        return view('panel.mesas.index', [
            ...$datos,
            'areaActiva' => $datos['areas']->firstWhere('id', (int) $request->query('area')) ?? $datos['areas']->first(),
            'puedeGestionar' => $gestiona,
            'puedeAtender' => $actor->can('atender', Cuenta::class),
        ]);
    }

    private function gestion(Request $request, Restaurante $restaurante): View
    {
        $areas = Area::query()
            ->where('restaurante_id', $restaurante->id)
            ->ordenadas()
            ->with(['mesas' => fn ($query) => $query->ordenadas()])
            ->get();

        return view('panel.mesas.gestion', [
            'areas' => $areas,
            'areaActiva' => $areas->firstWhere('id', (int) $request->query('area')) ?? $areas->first(),
            'puedeGestionar' => true,
            'cupo' => $this->gestion->cupo($restaurante),
            'planNombre' => $restaurante->plan?->nombre,
        ]);
    }

    public function store(MesaRequest $request): JsonResponse|RedirectResponse
    {
        $datos = $request->validated();
        $mesa = $this->gestion->crearMesa($request->user(), [
            'codigo' => $datos['codigo'],
            'capacidad' => (int) $datos['capacidad'],
            'area_id' => (int) $datos['area_id'],
        ]);

        return $this->responder($request, "Se creó la mesa {$mesa->codigo}.", $mesa->area_id);
    }

    public function storeVarias(AgregarMesasRequest $request): JsonResponse|RedirectResponse
    {
        $datos = $request->validated();
        $mesas = $this->gestion->agregarVarias($request->user(), [
            'area_id' => (int) $datos['area_id'],
            'prefijo' => $datos['prefijo'] ?? null,
            'desde' => (int) $datos['desde'],
            'hasta' => (int) $datos['hasta'],
            'capacidad' => (int) $datos['capacidad'],
        ]);

        $mensaje = $mesas->count() === 1
            ? "Se creó la mesa {$mesas->first()->codigo}."
            : "Se crearon {$mesas->count()} mesas: de {$mesas->first()->codigo} a {$mesas->last()->codigo}.";

        return $this->responder($request, $mensaje, (int) $datos['area_id']);
    }

    public function update(MesaRequest $request, Mesa $mesa): JsonResponse|RedirectResponse
    {
        $datos = $request->validated();
        $mesa = $this->gestion->actualizarMesa($request->user(), $mesa, [
            'codigo' => $datos['codigo'],
            'capacidad' => (int) $datos['capacidad'],
            'area_id' => (int) $datos['area_id'],
            ...(isset($datos['estado']) ? ['estado' => $datos['estado']] : []),
        ]);

        return $this->responder($request, "Se guardaron los cambios de la mesa {$mesa->codigo}.", $mesa->area_id);
    }

    public function estado(Request $request, Mesa $mesa): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $mesa);

        $estado = $request->validate(
            ['estado' => ['required', 'string', Rule::in(Mesa::ESTADOS_MANUALES)]],
            ['estado.required' => 'Indica el estado de la mesa.', 'estado.in' => 'Solo puedes marcar una mesa como disponible o fuera de servicio.'],
        )['estado'];

        $mesa = $this->gestion->cambiarEstadoMesa($request->user(), $mesa, $estado);

        return $this->responder($request, $mesa->estaFueraDeServicio()
            ? "La mesa {$mesa->codigo} quedó fuera de servicio."
            : "La mesa {$mesa->codigo} está disponible.", $mesa->area_id);
    }

    public function activa(Request $request, Mesa $mesa): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $mesa);

        $activa = $request->validate(['activa' => ['required', 'boolean']], [
            'activa.required' => 'Indica si la mesa queda activa.',
            'activa.boolean' => 'Indica si la mesa queda activa.',
        ])['activa'];

        $mesa = $this->gestion->cambiarActivaMesa($request->user(), $mesa, filter_var($activa, FILTER_VALIDATE_BOOLEAN));

        return $this->responder($request, $mesa->activa
            ? "La mesa {$mesa->codigo} está activa."
            : "La mesa {$mesa->codigo} quedó desactivada: ya no aparece en el mapa ni cuenta para tu plan.", $mesa->area_id);
    }

    public function mover(Request $request, Mesa $mesa): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $mesa);

        $direccion = $request->validate(['direccion' => ['required', Rule::in(['subir', 'bajar'])]])['direccion'];
        $this->gestion->moverMesa($request->user(), $mesa, $direccion);

        return $this->responder($request, "Se movió la mesa {$mesa->codigo}.", $mesa->area_id);
    }

    private function responder(Request $request, string $mensaje, ?int $areaId): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['mensaje' => $mensaje]);
        }

        return redirect()->route('panel.mesas.index', array_filter(['editar' => 1, 'area' => $areaId]))->with('status', $mensaje);
    }
}
