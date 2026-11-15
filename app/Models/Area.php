<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Database\Factories\AreaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zona del local (Salón principal, Terraza…). Las reglas de gestión viven en GestionarMesas.
 * Un área desactivada no se muestra a cajero ni mesero, pero sus mesas activas siguen contando para el plan.
 */
class Area extends Model
{
    /** @use HasFactory<AreaFactory> */
    use BelongsToRestaurante, HasFactory;

    protected $table = 'areas';

    protected $fillable = [
        'restaurante_id',
        'nombre',
        'activa',
        'orden',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function scopeOrdenadas(Builder $query): void
    {
        $query->orderBy('orden')->orderBy('id');
    }

    /**
     * @return HasMany<Mesa, $this>
     */
    public function mesas(): HasMany
    {
        return $this->hasMany(Mesa::class);
    }
}
