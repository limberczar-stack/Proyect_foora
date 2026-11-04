<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoItem extends Model
{
    protected $table = 'pedido_items';

    protected $fillable = [
        'pedido_id',
        'producto_id',
        'variante_id',
        'variante_nombre',
        'nombre',
        'cantidad',
        'precio_unitario',
        'subtotal',
        'estado',
        'observacion',
        'tiempo_estimado_min',
        'listo_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'precio_unitario' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tiempo_estimado_min' => 'integer',
            'listo_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ProductoVariante, $this>
     */
    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    /**
     * @return HasMany<PedidoItemOpcion, $this>
     */
    public function opciones(): HasMany
    {
        return $this->hasMany(PedidoItemOpcion::class);
    }
}
