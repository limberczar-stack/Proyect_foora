<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcupacionMesa extends Model
{
    use BelongsToRestaurante;

    protected $table = 'ocupaciones_mesa';

    protected $fillable = [
        'restaurante_id',
        'mesa_id',
        'reserva_id',
        'pedido_id',
        'mesero_id',
        'personas',
        'ingreso_at',
        'salida_at',
        'consumo_total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personas' => 'integer',
            'ingreso_at' => 'datetime',
            'salida_at' => 'datetime',
            'consumo_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Mesa, $this>
     */
    public function mesa(): BelongsTo
    {
        return $this->belongsTo(Mesa::class);
    }

    /**
     * @return BelongsTo<Reserva, $this>
     */
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    /**
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function mesero(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mesero_id');
    }
}
