<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Carta\CategoriaRequest;
use App\Models\CategoriaProducto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoriaController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', CategoriaProducto::class);

        $categorias = CategoriaProducto::raiz()
            ->ordenadas()
            ->withCount('productos')
            ->with(['hijas' => fn ($query) => $query->withCount('productos')])
            ->get();

        return view('panel.carta.categorias.index', ['categorias' => $categorias]);
    }

    public function store(CategoriaRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        $categoria = CategoriaProducto::create([
            ...$datos,
            'orden' => $this->siguienteOrden($datos['parent_id']),
        ]);

        return redirect()
            ->route('panel.carta.categorias.index')
            ->with('status', "Se creó la categoría «{$categoria->nombre}».");
    }

    public function update(CategoriaRequest $request, CategoriaProducto $categoria): RedirectResponse
    {
        $datos = $request->validated();

        if ($datos['parent_id'] !== $categoria->parent_id) {
            $datos['orden'] = $this->siguienteOrden($datos['parent_id']);
        }

        $categoria->update($datos);

        return redirect()
            ->route('panel.carta.categorias.index')
            ->with('status', "Se guardaron los cambios de «{$categoria->nombre}».");
    }

    public function estado(CategoriaProducto $categoria): RedirectResponse
    {
        Gate::authorize('update', $categoria);

        $categoria->update(['activa' => ! $categoria->activa]);

        return redirect()
            ->route('panel.carta.categorias.index')
            ->with('status', $categoria->activa
                ? "«{$categoria->nombre}» está activa."
                : "«{$categoria->nombre}» quedó desactivada: no se muestra en la carta.");
    }

    /**
     * Sube o baja una posición entre sus hermanas (mismo nivel).
     */
    public function mover(Request $request, CategoriaProducto $categoria): RedirectResponse
    {
        Gate::authorize('update', $categoria);

        $direccion = $request->validate(['direccion' => ['required', Rule::in(['subir', 'bajar'])]])['direccion'];

        DB::transaction(function () use ($categoria, $direccion): void {
            $hermanas = CategoriaProducto::where('parent_id', $categoria->parent_id)->ordenadas()->get()->values();
            $posicion = $hermanas->search(fn (CategoriaProducto $c): bool => $c->is($categoria));
            $destino = $direccion === 'subir' ? $posicion - 1 : $posicion + 1;

            if ($destino < 0 || $destino >= $hermanas->count()) {
                return;
            }

            $orden = $hermanas->all();
            [$orden[$posicion], $orden[$destino]] = [$orden[$destino], $orden[$posicion]];

            foreach ($orden as $indice => $hermana) {
                if ($hermana->orden !== $indice) {
                    $hermana->update(['orden' => $indice]);
                }
            }
        });

        return redirect()->route('panel.carta.categorias.index');
    }

    public function destroy(CategoriaProducto $categoria): RedirectResponse
    {
        Gate::authorize('delete', $categoria);

        $subcategorias = $categoria->hijas()->count();

        if ($subcategorias > 0) {
            return redirect()
                ->route('panel.carta.categorias.index')
                ->with('error', "No puedes eliminar «{$categoria->nombre}» porque tiene {$subcategorias} ".($subcategorias === 1 ? 'subcategoría' : 'subcategorías').'. Elimínalas o muévelas primero.');
        }

        $productos = $categoria->productos()->count();

        if ($productos > 0) {
            return redirect()
                ->route('panel.carta.categorias.index')
                ->with('error', "No puedes eliminar «{$categoria->nombre}» porque tiene {$productos} ".($productos === 1 ? 'producto' : 'productos').'. Muévelos a otra categoría o elimínalos primero.');
        }

        $categoria->delete();

        return redirect()
            ->route('panel.carta.categorias.index')
            ->with('status', "Se eliminó la categoría «{$categoria->nombre}».");
    }

    private function siguienteOrden(?int $parentId): int
    {
        $maximo = CategoriaProducto::where('parent_id', $parentId)->max('orden');

        return $maximo === null ? 0 : $maximo + 1;
    }
}
