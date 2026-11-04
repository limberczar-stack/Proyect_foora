<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Carta\ProductoRequest;
use App\Models\CategoriaProducto;
use App\Models\Producto;
use App\Models\ProductoVariante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class ProductoController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Producto::class);

        $categoriaId = $request->integer('categoria') ?: null;
        $busqueda = trim((string) $request->string('q'));
        $soloIncompletos = $request->boolean('incompletos');

        $productos = Producto::query()
            ->with(['categoria.parent'])
            ->withCount('variantes')
            ->when($categoriaId, function ($query) use ($categoriaId): void {
                $ids = CategoriaProducto::whereKey($categoriaId)->orWhere('parent_id', $categoriaId)->pluck('id');
                $query->whereIn('categoria_id', $ids);
            })
            ->when($busqueda !== '', fn ($query) => $query->where('nombre', 'like', '%'.addcslashes($busqueda, '%_\\').'%'))
            ->when($soloIncompletos, fn ($query) => $query->incompletos())
            ->orderBy('orden')
            ->orderBy('nombre')
            ->paginate(20)
            ->withQueryString();

        return view('panel.carta.productos.index', [
            'productos' => $productos,
            'categorias' => CategoriaProducto::raiz()->ordenadas()->with('hijas')->get(),
            'categoriaId' => $categoriaId,
            'busqueda' => $busqueda,
            'soloIncompletos' => $soloIncompletos,
            'totalProductos' => Producto::count(),
            'totalIncompletos' => Producto::incompletos()->count(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Producto::class);

        return view('panel.carta.productos.form', [
            'producto' => new Producto(['tipo' => 'simple', 'estacion' => 'cocina', 'disponible' => true]),
            'categorias' => CategoriaProducto::raiz()->ordenadas()->with('hijas')->get(),
        ]);
    }

    public function store(ProductoRequest $request): RedirectResponse
    {
        $producto = $this->guardar(new Producto, $request);

        return redirect()
            ->route('panel.carta.productos.index')
            ->with('status', "«{$producto->nombre}» se agregó a la carta.");
    }

    public function edit(Producto $producto): View
    {
        Gate::authorize('update', $producto);

        $producto->load('variantes');

        return view('panel.carta.productos.form', [
            'producto' => $producto,
            'categorias' => CategoriaProducto::raiz()->ordenadas()->with('hijas')->get(),
        ]);
    }

    public function update(ProductoRequest $request, Producto $producto): RedirectResponse
    {
        $this->guardar($producto, $request);

        return redirect()
            ->route('panel.carta.productos.index')
            ->with('status', "Se guardaron los cambios de «{$producto->nombre}».");
    }

    public function destroy(Producto $producto): RedirectResponse
    {
        Gate::authorize('delete', $producto);

        $producto->delete();

        return redirect()
            ->back()
            ->with('status', "«{$producto->nombre}» se eliminó de la carta.");
    }

    /**
     * Interruptor de disponibilidad (AJAX): guarda al instante.
     */
    public function disponibilidad(Request $request, Producto $producto): JsonResponse
    {
        Gate::authorize('cambiarDisponibilidad', $producto);

        $datos = $request->validate(['disponible' => ['required', 'boolean']]);

        $producto->update(['disponible' => (bool) $datos['disponible']]);

        return response()->json([
            'disponible' => $producto->disponible,
            'mensaje' => $producto->disponible
                ? "«{$producto->nombre}» está disponible."
                : "«{$producto->nombre}» quedó como agotado.",
        ]);
    }

    /**
     * Guarda el producto, su imagen y sus variantes en una transacción.
     */
    private function guardar(Producto $producto, ProductoRequest $request): Producto
    {
        $datos = $request->validated();
        $variantes = $datos['tipo'] === 'variantes' ? $datos['variantes'] : [];
        $imagenAnterior = $producto->imagen;
        $imagenNueva = $request->hasFile('imagen') ? $request->file('imagen')->store('productos', 'public') : null;

        try {
            DB::transaction(function () use ($producto, $datos, $variantes, $imagenNueva): void {
                $producto->fill([
                    'categoria_id' => $datos['categoria_id'],
                    'tipo' => $datos['tipo'],
                    'nombre' => $datos['nombre'],
                    'descripcion' => $datos['descripcion'] ?? null,
                    'precio' => $variantes === [] ? $datos['precio'] : min(array_column($variantes, 'precio')),
                    'estacion' => $datos['estacion'],
                    'destacado' => $datos['destacado'],
                    'disponible' => $datos['disponible'],
                    'etiquetas' => $datos['etiquetas'] ?? [],
                ]);

                if ($imagenNueva) {
                    $producto->imagen = $imagenNueva;
                } elseif ($datos['quitar_imagen']) {
                    $producto->imagen = null;
                }

                $producto->save();

                $this->sincronizarVariantes($producto, $variantes);
                $producto->recalcularPrecio();
            });
        } catch (Throwable $e) {
            if ($imagenNueva) {
                Storage::disk('public')->delete($imagenNueva);
            }

            throw $e;
        }

        if ($imagenAnterior && $imagenAnterior !== $producto->imagen) {
            Storage::disk('public')->delete($imagenAnterior);
        }

        return $producto;
    }

    /**
     * Sincroniza por id: actualiza las existentes, crea las nuevas y borra solo las quitadas.
     * Un id que no pertenece a este producto se trata como variante nueva.
     *
     * @param  list<array{id: ?int, nombre: string, precio: string|float, disponible: bool}>  $filas
     */
    private function sincronizarVariantes(Producto $producto, array $filas): void
    {
        $existentes = ProductoVariante::where('producto_id', $producto->id)->get()->keyBy('id');
        $conservar = [];

        foreach (array_values($filas) as $orden => $fila) {
            $atributos = [
                'nombre' => $fila['nombre'],
                'precio' => $fila['precio'],
                'disponible' => $fila['disponible'],
                'orden' => $orden,
            ];

            $variante = $fila['id'] !== null ? $existentes->get($fila['id']) : null;

            if ($variante) {
                $variante->update($atributos);
            } else {
                $variante = $producto->variantes()->create($atributos);
            }

            $conservar[] = $variante->id;
        }

        ProductoVariante::where('producto_id', $producto->id)->whereNotIn('id', $conservar)->delete();
    }
}
