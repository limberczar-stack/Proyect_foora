<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promocion extends Model
{
    use BelongsToRestaurante, SoftDeletes;

    protected $table = 'promociones';

    protected $fillable = [
        'restaurante_id',
        'nombre',
        'descripcion',
        'imagen',
        'tipo',
        'valor',
        'monto_minimo',
        'aplica_delivery',
        'aplica_recojo',
        'aplica_salon',
        'fecha_inicio',
        'fecha_fin',
        'limite_usos',
        'usos',
        'activa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'monto_minimo' => 'decimal:2',
            'aplica_delivery' => 'boolean',
            'aplica_recojo' => 'boolean',
            'aplica_salon' => 'boolean',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'limite_usos' => 'integer',
            'usos' => 'integer',
            'activa' => 'boolean',
        ];
    }

    /**
     * Si no tiene productos asociados, la promoción aplica a toda la carta.
     *
     * @return BelongsToMany<Producto, $this>
     */
    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'promocion_productos', 'promocion_id', 'producto_id');
    }

    /**
     * @return HasMany<Pedido, $this>
     */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    /**
     * @return HasMany<PromocionUso, $this>
     */
    public function usosRegistrados(): HasMany
    {
        return $this->hasMany(PromocionUso::class);
    }
}
