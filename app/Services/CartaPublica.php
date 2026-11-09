<?php

namespace App\Services;

use App\Models\CategoriaProducto;
use App\Models\GrupoOpcion;
use App\Models\Opcion;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\Restaurante;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lecturas de la vitrina pública.
 *
 * Aquí no se confía en el scope del restaurante: sin sesión no filtra nada, y con el usuario de
 * otro local filtraría lo que no debe. Toda consulta lo quita y filtra por restaurante_id.
 */
class CartaPublica
{
    private const SCOPE = Producto::RESTAURANTE_SCOPE;

    /**
     * El restaurante publicado con este slug, o 404. Uno suspendido o vencido también se encuentra:
     * quien lo llama decide con operativo() qué mostrar.
     */
    public function restaurante(string $slug): Restaurante
    {
        return Restaurante::query()
            ->where('slug', $slug)
            ->where('visible_en_app', true)
            ->firstOrFail();
    }

    public function restauranteConDatos(string $slug): Restaurante
    {
        return $this->restaurante($slug)->load([
            'horariosAtencion' => fn ($query) => $query->withoutGlobalScope(self::SCOPE),
            'metodosPago' => fn ($query) => $query->wherePivot('activo', true)->orderBy('metodos_pago.id'),
        ]);
    }

    /**
     * Carta visible en orden: categorías activas de primer nivel, con sus productos directos y sus
     * subcategorías activas. Una sección sin productos no se incluye.
     *
     * @return list<array{categoria: CategoriaProducto, productos: list<Producto>, hijas: list<array{categoria: CategoriaProducto, productos: list<Producto>}>}>
     */
    public function secciones(Restaurante $restaurante): array
    {
        $categorias = CategoriaProducto::query()
            ->withoutGlobalScope(self::SCOPE)
            ->where('restaurante_id', $restaurante->id)
            ->where('activa', true)
            ->ordenadas()
            ->get();

        $raices = $categorias->whereNull('parent_id');
        $visibles = $categorias->filter(fn (CategoriaProducto $categoria): bool => $categoria->parent_id === null || $raices->contains('id', $categoria->parent_id));

        $productos = $this->productos($restaurante, $visibles->modelKeys())->groupBy('categoria_id');
        $deCategoria = fn (CategoriaProducto $categoria): array => $productos->get($categoria->id, new Collection)->all();

        $secciones = [];

        foreach ($raices as $raiz) {
            $hijas = $visibles
                ->where('parent_id', $raiz->id)
                ->map(fn (CategoriaProducto $hija): array => ['categoria' => $hija, 'productos' => $deCategoria($hija)])
                ->filter(fn (array $hija): bool => $hija['productos'] !== [])
                ->values()
                ->all();

            if ($deCategoria($raiz) !== [] || $hijas !== []) {
                $secciones[] = ['categoria' => $raiz, 'productos' => $deCategoria($raiz), 'hijas' => $hijas];
            }
        }

        return $secciones;
    }

    /**
     * Datos del detalle de cada producto, por id. Los precios sirven para mostrar el total
     * mientras se elige; el carrito siempre muestra los que devuelve el servidor.
     *
     * @param  list<array{categoria: CategoriaProducto, productos: list<Producto>, hijas: list<array{categoria: CategoriaProducto, productos: list<Producto>}>}>  $secciones
     * @return array<int, array<string, mixed>>
     */
    public function productosParaNavegador(array $secciones): array
    {
        $datos = [];

        foreach ($secciones as $seccion) {
            $productos = [...$seccion['productos'], ...array_merge(...array_column($seccion['hijas'], 'productos') ?: [[]])];

            foreach ($productos as $producto) {
                $datos[$producto->id] = $this->productoParaNavegador($producto);
            }
        }

        return $datos;
    }

    /**
     * @param  array<int, int>  $categoriaIds
     * @return Collection<int, Producto>
     */
    private function productos(Restaurante $restaurante, array $categoriaIds): Collection
    {
        return Producto::query()
            ->withoutGlobalScope(self::SCOPE)
            ->where('restaurante_id', $restaurante->id)
            ->whereIn('categoria_id', $categoriaIds)
            ->with([
                'variantes',
                'gruposOpcion' => fn ($query) => $query
                    ->withoutGlobalScope(self::SCOPE)
                    ->where('grupos_opcion.restaurante_id', $restaurante->id)
                    ->where('grupos_opcion.activo', true)
                    ->with('opciones'),
            ])
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function productoParaNavegador(Producto $producto): array
    {
        return [
            'id' => $producto->id,
            'nombre' => $producto->nombre,
            'descripcion' => $producto->descripcion,
            'imagen' => $producto->imagenUrl(),
            'tipo' => $producto->tipo,
            'precio' => $producto->precio,
            'personas' => $producto->personas,
            'etiquetas' => array_values(array_intersect_key(Producto::ETIQUETAS, array_flip($producto->etiquetas ?? []))),
            'variantes' => $producto->variantes->map(fn (ProductoVariante $variante): array => [
                'id' => $variante->id,
                'nombre' => $variante->nombre,
                'precio' => $variante->precio,
                'disponible' => $variante->disponible,
            ])->all(),
            'grupos' => $producto->gruposOpcion->map(fn (GrupoOpcion $grupo): array => [
                'id' => $grupo->id,
                'nombre' => $grupo->nombre,
                'tipo' => $grupo->tipo,
                'obligatorio' => $grupo->minimoElegible() > 0,
                'minimo' => $grupo->minimoElegible(),
                'maximo' => $grupo->maximoElegible(),
                'regla' => $grupo->descripcionRegla(),
                'opciones' => $grupo->opciones->map(fn (Opcion $opcion): array => [
                    'id' => $opcion->id,
                    'nombre' => $opcion->nombre,
                    'precio_extra' => $opcion->precio_extra,
                    'disponible' => $opcion->disponible,
                ])->all(),
            ])->all(),
        ];
    }
}
