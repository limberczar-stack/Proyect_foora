<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestauranteMetodoPago extends Model
{
    use BelongsToRestaurante;

    protected $table = 'restaurante_metodos_pago';

    protected $fillable = [
        'restaurante_id',
        'metodo_pago_id',
        'activo',
        'numero_cuenta',
        'titular',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MetodoPago, $this>
     */
    public function metodoPago(): BelongsTo
    {
        return $this->belongsTo(MetodoPago::class);
    }
}
