<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuscripcionPago extends Model
{
    protected $table = 'suscripcion_pagos';

    protected $fillable = [
        'suscripcion_id',
        'monto',
        'metodo',
        'referencia',
        'periodo_desde',
        'periodo_hasta',
        'pagado_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'periodo_desde' => 'date',
            'periodo_hasta' => 'date',
            'pagado_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Suscripcion, $this>
     */
    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }
}
