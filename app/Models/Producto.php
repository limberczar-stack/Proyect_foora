<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use BelongsToRestaurante, SoftDeletes;

    protected $table = 'productos';

    protected $fillable = [
        'restaurante_id',
        'categoria_id',
        'nombre',
        'descripcion',
        'imagen',
        'precio',
        'disponible',
        'destacado',
        'estacion',
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
            'destacado' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CategoriaProducto, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'categoria_id');
    }

    /**
     * @return HasMany<PedidoItem, $this>
     */
    public function pedidoItems(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }

    /**
     * @return BelongsToMany<Promocion, $this>
     */
    public function promociones(): BelongsToMany
    {
        return $this->belongsToMany(Promocion::class, 'promocion_productos', 'producto_id', 'promocion_id');
    }
}
