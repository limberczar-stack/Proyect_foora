<?php

namespace App\Services;

use App\Models\GrupoOpcion;
use App\Models\Opcion;
use App\Models\Producto;
use App\Models\ProductoVariante;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Valida una línea de pedido y calcula su precio.
 *
 * Solo recibe ids y cantidad: los precios salen siempre de la base de datos, nunca del cliente.
 * Los montos se suman en céntimos enteros para evitar errores de redondeo.
 */
class CalculadorLineaPedido
{
    /** Tope de negocio por línea. */
    public const CANTIDAD_MAXIMA = 50;

    /** Límite técnico de pedido_items.cantidad (SMALLINT UNSIGNED). */
    private const LIMITE_COLUMNA_CANTIDAD = 65535;

    /** Límite técnico de pedido_items.precio_unitario (DECIMAL(8,2)), en céntimos. */
    private const LIMITE_COLUMNA_PRECIO_CENTIMOS = 99_999_999;

    /**
     * El producto se busca con el scope del restaurante: el personal de otro local no lo encuentra
     * y un cliente de la vitrina, sin restaurante, sí. Grupos y variantes se comparan contra
     * el producto de forma explícita, porque para ese cliente el scope no filtra nada.
     *
     * @param  array<int, int|string>  $opcionIds
     *
     * @throws ValidationException
     */
    public function calcular(int $productoId, ?int $varianteId, array $opcionIds, int $cantidad): LineaPedidoCalculada
    {
        $producto = Producto::with('categoria.parent')->find($productoId);

        if (! $producto) {
            throw ValidationException::withMessages(['producto_id' => 'Este producto ya no está en la carta.']);
        }

        [$variante, $erroresVariante] = $this->resolverVariante($producto, $varianteId);
        [$elegidas, $erroresOpciones] = $this->resolverOpciones($producto, $opcionIds);

        $errores = [
            ...$this->erroresDeProducto($producto),
            ...$this->erroresDeCantidad($cantidad),
            ...$erroresVariante,
            ...$erroresOpciones,
        ];

        $precioCentimos = $this->aCentimos($variante->precio ?? $producto->precio)
            + $elegidas->sum(fn (Opcion $opcion): int => $this->aCentimos($opcion->precio_extra));

        if ($errores === [] && $precioCentimos > self::LIMITE_COLUMNA_PRECIO_CENTIMOS) {
            $errores['opciones'] = 'El precio de esta línea supera el máximo permitido.';
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return new LineaPedidoCalculada(
            productoId: $producto->id,
            nombre: $producto->nombre,
            varianteId: $variante?->id,
            varianteNombre: $variante?->nombre,
            opciones: $elegidas->map(fn (Opcion $opcion): array => [
                'opcion_id' => $opcion->id,
                'grupo_nombre' => $opcion->grupo->nombre,
                'nombre' => $opcion->nombre,
                'precio_extra' => $this->aSoles($this->aCentimos($opcion->precio_extra)),
            ])->all(),
            cantidad: $cantidad,
            precioUnitario: $this->aSoles($precioCentimos),
            subtotal: $this->aSoles($precioCentimos * $cantidad),
        );
    }

    /**
     * @return array<string, string>
     */
    private function erroresDeProducto(Producto $producto): array
    {
        $categoria = $producto->categoria;

        if (! $producto->disponible) {
            return ['producto_id' => "«{$producto->nombre}» no está disponible."];
        }

        if ($categoria && (! $categoria->activa || $categoria->parent?->activa === false)) {
            return ['producto_id' => "«{$producto->nombre}» no se muestra en la carta."];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function erroresDeCantidad(int $cantidad): array
    {
        $maxima = min(self::CANTIDAD_MAXIMA, self::LIMITE_COLUMNA_CANTIDAD);

        return match (true) {
            $cantidad < 1 => ['cantidad' => 'La cantidad debe ser al menos 1.'],
            $cantidad > $maxima => ['cantidad' => "La cantidad máxima por línea es {$maxima}."],
            default => [],
        };
    }

    /**
     * @return array{0: ?ProductoVariante, 1: array<string, string>}
     */
    private function resolverVariante(Producto $producto, ?int $varianteId): array
    {
        if (! $producto->tieneVariantes()) {
            return $varianteId === null
                ? [null, []]
                : [null, ['variante_id' => "«{$producto->nombre}» no tiene variantes."]];
        }

        if ($varianteId === null) {
            return [null, ['variante_id' => "Elige una variante de «{$producto->nombre}»."]];
        }

        $variante = ProductoVariante::where('producto_id', $producto->id)->find($varianteId);

        if (! $variante) {
            return [null, ['variante_id' => "La variante elegida no pertenece a «{$producto->nombre}»."]];
        }

        return [
            $variante,
            $variante->disponible ? [] : ['variante_id' => "La variante «{$variante->nombre}» no está disponible."],
        ];
    }

    /**
     * Devuelve las opciones elegidas en el orden de la carta: por grupo y por opción.
     *
     * @param  array<int, int|string>  $opcionIds
     * @return array{0: Collection<int, Opcion>, 1: array<string, string>}
     */
    private function resolverOpciones(Producto $producto, array $opcionIds): array
    {
        foreach ($opcionIds as $opcionId) {
            if (! is_int($opcionId) && ! (is_string($opcionId) && ctype_digit($opcionId))) {
                return [new Collection, ['opciones' => 'Las opciones elegidas no son válidas.']];
            }
        }

        $ids = array_map(intval(...), array_values($opcionIds));
        $errores = [];

        if (count($ids) !== count(array_unique($ids))) {
            $errores['opciones'] = 'Hay una opción repetida.';
        }

        $grupos = $this->gruposVisibles($producto);
        $opciones = $grupos->flatMap(fn (GrupoOpcion $grupo): EloquentCollection => $grupo->opciones);

        if (array_diff($ids, $opciones->pluck('id')->all()) !== []) {
            $errores['opciones'] = "Una de las opciones elegidas no pertenece a «{$producto->nombre}».";
        }

        foreach ($grupos as $grupo) {
            $errorGrupo = $this->errorDeGrupo($grupo, $grupo->opciones->whereIn('id', $ids));

            if ($errorGrupo !== null) {
                $errores["grupos.{$grupo->id}"] = $errorGrupo;
            }
        }

        return [$opciones->whereIn('id', $ids)->values(), $errores];
    }

    /**
     * Grupos que el cliente ve en este producto. Un grupo inactivo no se exige ni se puede elegir,
     * y uno de otro restaurante nunca cuenta, aunque esté enlazado.
     *
     * @return Collection<int, GrupoOpcion>
     */
    private function gruposVisibles(Producto $producto): Collection
    {
        $grupos = $producto->gruposOpcion()
            ->withoutGlobalScope(GrupoOpcion::RESTAURANTE_SCOPE)
            ->where('grupos_opcion.restaurante_id', $producto->restaurante_id)
            ->where('grupos_opcion.activo', true)
            ->with('opciones')
            ->get();

        foreach ($grupos as $grupo) {
            $grupo->opciones->each(fn (Opcion $opcion): Opcion => $opcion->setRelation('grupo', $grupo));
        }

        return $grupos->toBase();
    }

    /**
     * @param  EloquentCollection<int, Opcion>  $elegidas
     */
    private function errorDeGrupo(GrupoOpcion $grupo, EloquentCollection $elegidas): ?string
    {
        $agotada = $elegidas->firstWhere('disponible', false);

        if ($agotada) {
            return "«{$agotada->nombre}» no está disponible.";
        }

        $minimo = $grupo->minimoElegible();
        $maximo = $grupo->maximoElegible();
        $cantidad = $elegidas->count();

        if ($cantidad < $minimo) {
            return $grupo->opciones->where('disponible', true)->count() < $minimo
                ? "«{$grupo->nombre}» no tiene suficientes opciones disponibles."
                : "Elige al menos {$minimo} ".($minimo === 1 ? 'opción' : 'opciones')." en «{$grupo->nombre}».";
        }

        if ($maximo !== null && $cantidad > $maximo) {
            return "Elige como máximo {$maximo} ".($maximo === 1 ? 'opción' : 'opciones')." en «{$grupo->nombre}».";
        }

        return null;
    }

    private function aCentimos(string|float|null $monto): int
    {
        return (int) round((float) $monto * 100);
    }

    private function aSoles(int $centimos): string
    {
        return number_format($centimos / 100, 2, '.', '');
    }
}
