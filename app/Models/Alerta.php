<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Alerta extends Model
{
    use BelongsToRestaurante;

    protected $table = 'alertas';

    protected $fillable = [
        'restaurante_id',
        'tipo',
        'prioridad',
        'titulo',
        'mensaje',
        'referencia_tipo',
        'referencia_id',
        'leida',
        'leida_at',
        'resuelta_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leida' => 'boolean',
            'leida_at' => 'datetime',
            'resuelta_at' => 'datetime',
        ];
    }

    /**
     * Pedido, reserva o producto que originó la alerta (alias del morph map).
     *
     * @return MorphTo<Model, $this>
     */
    public function referencia(): MorphTo
    {
        return $this->morphTo('referencia', 'referencia_tipo', 'referencia_id');
    }
}
