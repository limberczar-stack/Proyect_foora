<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pago manual de una suscripción. Nunca se borra: si hubo un error se anula con una marca.
 */
class SuscripcionPago extends Model
{
    /**
     * @var list<string>
     */
    public const METODOS = ['Transferencia', 'Yape', 'Plin', 'Efectivo', 'Tarjeta', 'Otro'];

    protected $table = 'suscripcion_pagos';

    /**
     * El motivo de una anulación es del equipo de FOORA: nunca sale a JSON ni llega al local.
     *
     * @var list<string>
     */
    protected $hidden = ['motivo_anulacion'];

    protected $fillable = [
        'suscripcion_id',
        'monto',
        'metodo',
        'referencia',
        'periodo_desde',
        'periodo_hasta',
        'pagado_at',
        'registrado_por',
        'vence_antes',
        'estado_antes',
        'precio_antes',
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
            'vence_antes' => 'date',
            'precio_antes' => 'decimal:2',
            'anulado_at' => 'datetime',
        ];
    }

    public function estaAnulado(): bool
    {
        return $this->anulado_at !== null;
    }

    /**
     * @param  Builder<SuscripcionPago>  $query
     */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('anulado_at');
    }

    /**
     * @return BelongsTo<Suscripcion, $this>
     */
    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
