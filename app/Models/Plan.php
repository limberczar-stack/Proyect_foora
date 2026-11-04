<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $table = 'planes';

    protected $fillable = [
        'nombre',
        'precio_mensual',
        'max_mesas',
        'max_usuarios',
        'max_productos',
        'incluye_reservas',
        'incluye_promociones',
        'incluye_reportes',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_mensual' => 'decimal:2',
            'max_mesas' => 'integer',
            'max_usuarios' => 'integer',
            'max_productos' => 'integer',
            'incluye_reservas' => 'boolean',
            'incluye_promociones' => 'boolean',
            'incluye_reportes' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Restaurante, $this>
     */
    public function restaurantes(): HasMany
    {
        return $this->hasMany(Restaurante::class);
    }

    /**
     * @return HasMany<Suscripcion, $this>
     */
    public function suscripciones(): HasMany
    {
        return $this->hasMany(Suscripcion::class);
    }
}
