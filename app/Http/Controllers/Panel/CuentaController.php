<?php

namespace App\Http\Controllers\Panel;

use App\Exceptions\RondasSinServir;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuentas\AbrirCuentaRequest;
use App\Models\Cuenta;
use App\Models\Mesa;
use App\Models\User;
use App\Services\GestionarCuentas;
use App\Services\PlanoMesas;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Cuentas de salón dentro del módulo Mesas (404 con el módulo apagado). Los permisos por rol viven
 * en CuentaPolicy y las reglas en GestionarCuentas.
 *
 * Toda operación sobre una cuenta existente lleva la version que veía quien actúa: si cambió,
 * la respuesta es 409 (JSON) o una vuelta al plano con el aviso (sin JavaScript).
 */
class CuentaController extends Controller
{
    public function __construct(private GestionarCuentas $gestion) {}

    /**
     * Estado del plano para la actualización en vivo: cada mesa ya pintada y el resumen.
     */
    public function plano(Request $request, PlanoMesas $plano): JsonResponse
    {
        Gate::authorize('atender', Cuenta::class);

        $datos = $plano->para($request->user()->restaurante);
        $mesas = [];

        foreach ($datos['areas'] as $area) {
            foreach ($area->mesas as $mesa) {
                $mesas[$mesa->id] = view('panel.mesas._mesa', ['mesa' => $mesa, 'cuenta' => $datos['ocupaciones'][$mesa->id] ?? null])->render();
            }
        }

        return response()->json(['mesas' => $mesas, 'resumen' => $datos['resumen']])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Detalle de una cuenta con sus acciones: fragmento para la hoja inferior (AJAX) o página completa.
     */
    public function show(Request $request, Cuenta $cuenta): View
    {
        Gate::authorize('update', $cuenta);

        /** @var User $actor */
        $actor = $request->user();
        $cuenta->load([
            'mesero:id,name',
            'cerradaPor:id,name',
            'anuladaPor:id,name',
            'fusionadaEn:id,numero',
            'ocupacionesAbiertas.mesa:id,codigo',
            'pedidos' => fn ($query) => $query->with(['items.opciones', 'items.anuladoPor:id,name', 'creadoPor:id,name']),
        ]);

        // El total sale de los pedidos ya cargados: la misma regla que GestionarCuentas::totalCentimos.
        $totalCentimos = (int) $cuenta->pedidos->where('estado', '!=', 'cancelado')->sum(fn ($pedido): int => (int) round((float) $pedido->total * 100));

        $datos = ['cuenta' => $cuenta, 'totalCentimos' => $totalCentimos, 'demorada' => false, 'disponibles' => collect(), 'otras' => collect(), 'personal' => collect()];

        if ($cuenta->estaAbierta()) {
            $datos['demorada'] = $cuenta->minutosAbierta() >= GestionarCuentas::HORAS_PARA_RESALTAR * 60;
            $datos['disponibles'] = Mesa::query()
                ->where('activa', true)
                ->where('estado', 'disponible')
                ->whereHas('area', fn ($query) => $query->where('activa', true))
                ->with('area:id,nombre')
                ->orderBy('area_id')
                ->ordenadas()
                ->get(['id', 'area_id', 'codigo', 'capacidad']);
            $datos['otras'] = Cuenta::query()
                ->abiertas()
                ->whereKeyNot($cuenta->id)
                ->with('ocupacionesAbiertas.mesa:id,codigo')
                ->orderBy('numero')
                ->get();
            $datos['personal'] = User::query()
                ->where('restaurante_id', $actor->restaurante_id)
                ->where('activo', true)
                ->whereIn('rol', GestionarCuentas::ROLES_QUE_ATIENDEN)
                ->whereKeyNot($cuenta->mesero_id ?? 0)
                ->orderBy('name')
                ->get(['id', 'name', 'rol']);
        }

        return view($request->ajax() ? 'panel.mesas._cuenta' : 'panel.mesas.cuenta', $datos);
    }

    public function store(AbrirCuentaRequest $request): JsonResponse|RedirectResponse
    {
        $datos = $request->validated();

        return $this->ejecutar($request, function () use ($request, $datos): string {
            $cuenta = $this->gestion->abrir($request->user(), array_map(intval(...), $datos['mesas']), (int) $datos['comensales'], $datos['nota'] ?? null);

            return "Se abrió la cuenta #{$cuenta->numero}.";
        });
    }

    public function agregarMesa(Request $request, Cuenta $cuenta): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $cuenta);
        $datos = $this->validar($request, ['mesa_id' => ['required', 'integer']]);

        return $this->ejecutar($request, function () use ($request, $cuenta, $datos): string {
            $this->gestion->agregarMesa($request->user(), $cuenta, (int) $datos['version'], (int) $datos['mesa_id']);

            return "Se agregó la mesa a la cuenta #{$cuenta->numero}.";
        });
    }

    public function quitarMesa(Request $request, Cuenta $cuenta): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $cuenta);
        $datos = $this->validar($request, ['mesa_id' => ['required', 'integer']]);

        return $this->ejecutar($request, function () use ($request, $cuenta, $datos): string {
            $this->gestion->quitarMesa($request->user(), $cuenta, (int) $datos['version'], (int) $datos['mesa_id']);

            return "Se quitó la mesa de la cuenta #{$cuenta->numero}.";
        });
    }

    public function moverMesa(Request $request, Cuenta $cuenta): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $cuenta);
        $datos = $this->validar($request, ['desde_mesa_id' => ['required', 'integer'], 'hacia_mesa_id' => ['required', 'integer', 'different:desde_mesa_id']]);

        return $this->ejecutar($request, function () use ($request, $cuenta, $datos): string {
            $this->gestion->moverMesa($request->user(), $cuenta, (int) $datos['version'], (int) $datos['desde_mesa_id'], (int) $datos['hacia_mesa_id']);

            return "Se movió la cuenta #{$cuenta->numero} de mesa.";
        });
    }

    /**
     * La otra cuenta se busca dentro del local (404 si no es suya) y también lleva su version.
     */
    public function fusionar(Request $request, Cuenta $cuenta): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $cuenta);

        // El formulario envía la otra cuenta como "id:version" en un solo campo.
        if (is_string($request->input('otra')) && preg_match('/\A(\d+):(\d+)\z/', $request->input('otra'), $partes)) {
            $request->merge(['otra_cuenta_id' => (int) $partes[1], 'otra_version' => (int) $partes[2]]);
        }

        $datos = $this->validar($request, ['otra_cuenta_id' => ['required', 'integer'], 'otra_version' => ['required', 'integer', 'min:1']]);

        $otra = Cuenta::findOrFail((int) $datos['otra_cuenta_id']);
        Gate::authorize('update', $otra);

        return $this->ejecutar($request, function () use ($request, $cuenta, $otra, $datos): string {
            $this->gestion->fusionar($request->user(), $cuenta, (int) $datos['version'], $otra, (int) $datos['otra_version']);

            return "La cuenta #{$otra->numero} se unió con la #{$cuenta->numero}.";
        });
    }

    public function transferir(Request $request, Cuenta $cuenta): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $cuenta);
        $datos = $this->validar($request, ['mesero_id' => ['required', 'integer']]);

        return $this->ejecutar($request, function () use ($request, $cuenta, $datos): string {
            $cuenta = $this->gestion->transferir($request->user(), $cuenta, (int) $datos['version'], (int) $datos['mesero_id']);

            return "La cuenta #{$cuenta->numero} ahora es de {$cuenta->mesero->name}.";
        });
    }

    public function cerrar(Request $request, Cuenta $cuenta): JsonResponse|RedirectResponse
    {
        Gate::authorize('cerrar', $cuenta);
        $datos = $this->validar($request, [
            'total_centimos' => ['required', 'integer', 'min:0'],
            'confirmar' => ['nullable', 'boolean'],
        ]);

        try {
            return $this->ejecutar($request, function () use ($request, $cuenta, $datos): string {
                $this->gestion->cerrarExterno($request->user(), $cuenta, (int) $datos['version'], (int) $datos['total_centimos'], (bool) ($datos['confirmar'] ?? false));

                return "Se cerró la cuenta #{$cuenta->numero} y sus mesas quedaron disponibles.";
            });
        } catch (RondasSinServir $sinServir) {
            // Con JavaScript se muestran las rondas y se reenvía con confirmar; sin él, se vuelve al
            // detalle, donde el formulario de cierre ya ofrece confirmarlas.
            if ($request->expectsJson()) {
                return response()->json(['message' => $sinServir->getMessage(), 'requiere_confirmacion' => true, 'rondas' => $sinServir->rondas], 409);
            }

            return redirect()->route('panel.mesas.cuentas.show', $cuenta)->with('error', $sinServir->getMessage());
        }
    }

    public function anular(Request $request, Cuenta $cuenta): JsonResponse|RedirectResponse
    {
        Gate::authorize('anular', $cuenta);
        $datos = $this->validar($request, ['motivo' => ['required', 'string', 'min:3', 'max:255']]);

        return $this->ejecutar($request, function () use ($request, $cuenta, $datos): string {
            $this->gestion->anular($request->user(), $cuenta, (int) $datos['version'], $datos['motivo']);

            return "Se anuló la cuenta #{$cuenta->numero} y sus mesas quedaron disponibles.";
        });
    }

    /**
     * @param  array<string, array<int, mixed>>  $reglas
     * @return array<string, mixed>
     */
    private function validar(Request $request, array $reglas): array
    {
        return $request->validate(['version' => ['required', 'integer', 'min:1'], ...$reglas], [
            'version.*' => 'Vuelve a abrir la cuenta e inténtalo otra vez.',
            'mesa_id.*' => 'Elige una mesa.',
            'desde_mesa_id.*' => 'Elige la mesa que deja la cuenta.',
            'hacia_mesa_id.required' => 'Elige la mesa a la que pasa.',
            'hacia_mesa_id.integer' => 'Elige la mesa a la que pasa.',
            'hacia_mesa_id.different' => 'Elige una mesa distinta.',
            'otra_cuenta_id.*' => 'Elige la cuenta que quieres unir.',
            'otra_version.*' => 'Vuelve a abrir la cuenta e inténtalo otra vez.',
            'mesero_id.*' => 'Elige a quién pasa la cuenta.',
            'motivo.required' => 'Escribe el motivo de la anulación.',
            'motivo.min' => 'Escribe el motivo de la anulación (al menos 3 caracteres).',
            'motivo.max' => 'El motivo admite hasta 255 caracteres.',
            'total_centimos.*' => 'Vuelve a abrir la cuenta e inténtalo otra vez.',
            'confirmar.*' => 'Vuelve a abrir la cuenta e inténtalo otra vez.',
        ]);
    }

    /**
     * @param  Closure(): string  $operacion  devuelve el mensaje de éxito
     */
    private function ejecutar(Request $request, Closure $operacion): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['mensaje' => $operacion()]);
        }

        try {
            return redirect()->route('panel.mesas.index')->with('status', $operacion());
        } catch (RondasSinServir $sinServir) {
            throw $sinServir;
        } catch (ConflictHttpException $conflicto) {
            return redirect()->route('panel.mesas.index')->with('error', $conflicto->getMessage());
        }
    }
}
