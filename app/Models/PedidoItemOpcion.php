<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opción elegida en un ítem del pedido. Guarda copia del grupo, nombre y precio
 * para que el pedido no cambie si luego se edita la carta.
 */
class PedidoItemOpcion extends Model
{
    public $timestamps = false;

    protected $table = 'pedido_item_opciones';

    protected $fillable = [
        'pedido_item_id',
        'opcion_id',
        'grupo_nombre',
        'nombre',
        'precio_extra',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_extra' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PedidoItem, $this>
     */
    public function pedidoItem(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class);
    }

    /**
     * @return BelongsTo<Opcion, $this>
     */
    public function opcion(): BelongsTo
    {
        return $this->belongsTo(Opcion::class);
    }
}
