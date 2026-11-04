<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resena extends Model
{
    use BelongsToRestaurante;

    protected $table = 'resenas';

    protected $fillable = [
        'restaurante_id',
        'user_id',
        'pedido_id',
        'puntuacion',
        'comentario',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'puntuacion' => 'integer',
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
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }
}
