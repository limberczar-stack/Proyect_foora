<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Database\Factories\CategoriaProductoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaProducto extends Model
{
    /** @use HasFactory<CategoriaProductoFactory> */
    use BelongsToRestaurante, HasFactory;

    protected $table = 'categorias_producto';

    protected $fillable = [
        'restaurante_id',
        'parent_id',
        'nombre',
        'descripcion',
        'orden',
        'activa',
        'con_fotos',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'activa' => 'boolean',
            'con_fotos' => 'boolean',
        ];
    }

    public function esSubcategoria(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Categorías de primer nivel.
     *
     * @param  Builder<CategoriaProducto>  $query
     */
    public function scopeRaiz(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * @param  Builder<CategoriaProducto>  $query
     */
    public function scopeOrdenadas(Builder $query): void
    {
        $query->orderBy('orden')->orderBy('nombre');
    }

    /**
     * @return BelongsTo<CategoriaProducto, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'parent_id');
    }

    /**
     * @return HasMany<CategoriaProducto, $this>
     */
    public function hijas(): HasMany
    {
        return $this->hasMany(CategoriaProducto::class, 'parent_id')->ordenadas();
    }

    /**
     * @return HasMany<Producto, $this>
     */
    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'categoria_id');
    }
}
