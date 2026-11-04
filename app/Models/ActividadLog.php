<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActividadLog extends Model
{
    use BelongsToRestaurante;

    public const UPDATED_AT = null;

    protected $table = 'actividad_log';

    protected $fillable = [
        'restaurante_id',
        'user_id',
        'modulo',
        'accion',
        'descripcion',
        'subject_type',
        'subject_id',
        'datos_antes',
        'datos_despues',
        'ip',
        'dispositivo',
        'navegador',
        'revertible',
        'revertida_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'datos_antes' => 'array',
            'datos_despues' => 'array',
            'revertible' => 'boolean',
            'revertida_at' => 'datetime',
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
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
