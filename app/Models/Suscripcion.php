<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Suscripcion extends Model
{
    use BelongsToRestaurante;

    protected $table = 'suscripciones';

    protected $fillable = [
        'restaurante_id',
        'plan_id',
        'estado',
        'inicia_el',
        'vence_el',
        'precio_pactado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inicia_el' => 'date',
            'vence_el' => 'date',
            'precio_pactado' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<SuscripcionPago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(SuscripcionPago::class);
    }
}
