<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Database\Factories\MesaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mesa extends Model
{
    /** @use HasFactory<MesaFactory> */
    use BelongsToRestaurante, HasFactory;

    protected $table = 'mesas';

    protected $fillable = [
        'restaurante_id',
        'area_id',
        'codigo',
        'capacidad',
        'estado',
        'pos_x',
        'pos_y',
        'activa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacidad' => 'integer',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'activa' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * @return HasMany<Pedido, $this>
     */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    /**
     * @return HasMany<Reserva, $this>
     */
    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class);
    }

    /**
     * @return HasMany<OcupacionMesa, $this>
     */
    public function ocupaciones(): HasMany
    {
        return $this->hasMany(OcupacionMesa::class);
    }
}
