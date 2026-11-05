<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Carta\GrupoOpcionRequest;
use App\Models\GrupoOpcion;
use App\Models\Opcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class GrupoOpcionController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', GrupoOpcion::class);

        $busqueda = trim((string) $request->string('q'));

        $grupos = GrupoOpcion::query()
            ->with('opciones')
            ->withCount('productos')
            ->when($busqueda !== '', fn ($query) => $query->where('nombre', 'like', '%'.addcslashes($busqueda, '%_\\').'%'))
            ->orderBy('nombre')
            ->paginate(20)
            ->withQueryString();

        return view('panel.carta.grupos.index', [
            'grupos' => $grupos,
            'busqueda' => $busqueda,
            'totalGrupos' => GrupoOpcion::count(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', GrupoOpcion::class);

        return view('panel.carta.grupos.form', [
            'grupo' => new GrupoOpcion(['tipo' => 'unica', 'obligatorio' => false, 'min_opciones' => 0, 'max_opciones' => 1, 'activo' => true]),
        ]);
    }

    public function store(GrupoOpcionRequest $request): RedirectResponse
    {
        $grupo = $this->guardar(new GrupoOpcion, $request->validated());

        return redirect()
            ->route('panel.carta.grupos.index')
            ->with('status', "Se creó el grupo «{$grupo->nombre}».");
    }

    public function edit(GrupoOpcion $grupo): View
    {
        Gate::authorize('update', $grupo);

        $grupo->load('opciones');

        return view('panel.carta.grupos.form', ['grupo' => $grupo]);
    }

    public function update(GrupoOpcionRequest $request, GrupoOpcion $grupo): RedirectResponse
    {
        $this->guardar($grupo, $request->validated());

        return redirect()
            ->route('panel.carta.grupos.index')
            ->with('status', "Se guardaron los cambios de «{$grupo->nombre}».");
    }

    public function destroy(GrupoOpcion $grupo): RedirectResponse
    {
        Gate::authorize('delete', $grupo);

        $productos = $grupo->productos()->count();

        if ($productos > 0) {
            return redirect()
                ->route('panel.carta.grupos.index')
                ->with('error', "No puedes eliminar «{$grupo->nombre}» porque está asignado a {$productos} ".($productos === 1 ? 'producto' : 'productos').'. Quítalo de esos productos primero.');
        }

        $grupo->delete();

        return redirect()
            ->route('panel.carta.grupos.index')
            ->with('status', "Se eliminó el grupo «{$grupo->nombre}».");
    }

    /**
     * Interruptor de disponibilidad de una opción (AJAX). La ruta asegura que sea de este grupo.
     */
    public function disponibilidadOpcion(Request $request, GrupoOpcion $grupo, Opcion $opcion): JsonResponse
    {
        Gate::authorize('cambiarDisponibilidad', $grupo);

        $datos = $request->validate(['disponible' => ['required', 'boolean']]);

        $opcion->update(['disponible' => (bool) $datos['disponible']]);

        return response()->json([
            'disponible' => $opcion->disponible,
            'mensaje' => $opcion->disponible
                ? "«{$opcion->nombre}» está disponible."
                : "«{$opcion->nombre}» quedó como agotada.",
        ]);
    }

    /**
     * Guarda el grupo y sus opciones en una transacción.
     *
     * @param  array<string, mixed>  $datos
     */
    private function guardar(GrupoOpcion $grupo, array $datos): GrupoOpcion
    {
        DB::transaction(function () use ($grupo, $datos): void {
            $grupo->fill([
                'nombre' => $datos['nombre'],
                'tipo' => $datos['tipo'],
                'obligatorio' => $datos['obligatorio'],
                'min_opciones' => $datos['min_opciones'],
                'max_opciones' => $datos['max_opciones'],
                'activo' => $datos['activo'],
            ])->save();

            $this->sincronizarOpciones($grupo, $datos['opciones']);
        });

        return $grupo;
    }

    /**
     * Sincroniza por id: actualiza las existentes, crea las nuevas y borra solo las quitadas.
     * Un id que no pertenece a este grupo se trata como opción nueva.
     *
     * @param  list<array{id: ?int, nombre: string, precio_extra: string|float, disponible: bool}>  $filas
     */
    private function sincronizarOpciones(GrupoOpcion $grupo, array $filas): void
    {
        $existentes = Opcion::where('grupo_id', $grupo->id)->get()->keyBy('id');
        $conservar = [];

        foreach (array_values($filas) as $orden => $fila) {
            $atributos = [
                'nombre' => $fila['nombre'],
                'precio_extra' => $fila['precio_extra'],
                'disponible' => $fila['disponible'],
                'orden' => $orden,
            ];

            $opcion = $fila['id'] !== null ? $existentes->get($fila['id']) : null;

            if ($opcion) {
                $opcion->update($atributos);
            } else {
                $opcion = $grupo->opciones()->create($atributos);
            }

            $conservar[] = $opcion->id;
        }

        Opcion::where('grupo_id', $grupo->id)->whereNotIn('id', $conservar)->delete();
    }
}
