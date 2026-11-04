<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromocionUso extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'promocion_usos';

    protected $fillable = [
        'promocion_id',
        'pedido_id',
        'user_id',
        'descuento_aplicado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'descuento_aplicado' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Promocion, $this>
     */
    public function promocion(): BelongsTo
    {
        return $this->belongsTo(Promocion::class)->withTrashed();
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
