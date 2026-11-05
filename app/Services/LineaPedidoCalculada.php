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
}
