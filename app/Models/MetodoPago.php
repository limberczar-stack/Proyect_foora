<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetodoPago extends Model
{
    protected $table = 'metodos_pago';

    protected $fillable = ['codigo', 'nombre', 'requiere_validacion'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requiere_validacion' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Restaurante, $this>
     */
    public function restaurantes(): BelongsToMany
    {
        return $this->belongsToMany(Restaurante::class, 'restaurante_metodos_pago', 'metodo_pago_id', 'restaurante_id')
            ->withPivot(['id', 'activo', 'numero_cuenta', 'titular'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<RestauranteMetodoPago, $this>
     */
    public function restauranteMetodosPago(): HasMany
    {
        return $this->hasMany(RestauranteMetodoPago::class);
    }

    /**
     * @return HasMany<Pedido, $this>
     */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }
}
