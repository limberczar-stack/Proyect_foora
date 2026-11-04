<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reserva extends Model
{
    use BelongsToRestaurante;

    protected $table = 'reservas';

    protected $fillable = [
        'codigo',
        'restaurante_id',
        'user_id',
        'nombre_cliente',
        'telefono_cliente',
        'mesa_id',
        'fecha',
        'hora',
        'personas',
        'observaciones',
        'estado',
        'origen',
        'confirmada_at',
        'confirmada_por',
        'cancelada_at',
        'cancelacion_motivo',
        'recordatorio_enviado_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'personas' => 'integer',
            'confirmada_at' => 'datetime',
            'cancelada_at' => 'datetime',
            'recordatorio_enviado_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Mesa, $this>
     */
    public function mesa(): BelongsTo
    {
        return $this->belongsTo(Mesa::class);
    }

    /**
     * @return HasMany<ReservaAplazamiento, $this>
     */
    public function aplazamientos(): HasMany
    {
        return $this->hasMany(ReservaAplazamiento::class);
    }

    /**
     * @return HasMany<OcupacionMesa, $this>
     */
    public function ocupacionesMesa(): HasMany
    {
        return $this->hasMany(OcupacionMesa::class);
    }
}
