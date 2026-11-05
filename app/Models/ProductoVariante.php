<?php

namespace App\Models;

use Database\Factories\ProductoVarianteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductoVariante extends Model
{
    /** @use HasFactory<ProductoVarianteFactory> */
    use HasFactory;

    protected $table = 'producto_variantes';

    protected $fillable = [
        'producto_id',
        'nombre',
        'precio',
        'disponible',
        'orden',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'disponible' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    /**
     * @return HasMany<PedidoItem, $this>
     */
    public function pedidoItems(): HasMany
    {
        return $this->hasMany(PedidoItem::class, 'variante_id');
    }
}
