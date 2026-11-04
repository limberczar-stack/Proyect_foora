<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    use BelongsToRestaurante;

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

    /**
     * @return HasMany<Mesa, $this>
     */
    public function mesas(): HasMany
    {
        return $this->hasMany(Mesa::class);
    }
}
