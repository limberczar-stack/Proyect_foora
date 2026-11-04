<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservaAplazamiento extends Model
{
    protected $table = 'reserva_aplazamientos';

    protected $fillable = [
        'reserva_id',
        'hora_original',
        'hora_solicitada',
        'motivo',
        'estado',
        'resuelta_por',
        'resuelta_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resuelta_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Reserva, $this>
     */
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resueltaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelta_por');
    }
}
