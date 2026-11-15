<?php

namespace App\Services;

/**
 * Resultado de CalculadorLineaPedido: montos en soles con 2 decimales y copias de nombres,
 * listas para pedido_items y pedido_item_opciones.
 */
readonly class LineaPedidoCalculada
{
    /**
     * @param  list<array{opcion_id: int, grupo_nombre: string, nombre: string, precio_extra: string}>  $opciones
     */
    public function __construct(
        public int $productoId,
        public string $nombre,
        public ?int $varianteId,
        public ?string $varianteNombre,
        public array $opciones,
        public int $cantidad,
        public string $precioUnitario,
        public string $subtotal,
    ) {}

    /**
     * Una línea revisada tal como la recibe el navegador: válida o con sus errores.
     *
     * @param  list<string>  $errores
     * @return array{valida: bool, errores: list<string>, nombre: ?string, variante_nombre: ?string, opciones: list<array{grupo_nombre: string, nombre: string, precio_extra: string}>, precio_unitario: ?string, subtotal: ?string}
     */
    public static function paraNavegador(?self $linea, array $errores): array
    {
        return [
            'valida' => $linea !== null,
            'errores' => $errores,
            'nombre' => $linea?->nombre,
            'variante_nombre' => $linea?->varianteNombre,
            'opciones' => array_map(fn (array $opcion): array => [
                'grupo_nombre' => $opcion['grupo_nombre'],
                'nombre' => $opcion['nombre'],
                'precio_extra' => $opcion['precio_extra'],
            ], $linea->opciones ?? []),
            'precio_unitario' => $linea?->precioUnitario,
            'subtotal' => $linea?->subtotal,
        ];
    }
}
