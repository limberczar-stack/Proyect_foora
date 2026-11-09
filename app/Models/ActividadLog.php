<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

class ActividadLog extends Model
{
    use BelongsToRestaurante;

    public const UPDATED_AT = null;

    /**
     * Acciones del equipo de FOORA sobre el local: guardan los motivos de suspensión, cancelación y
     * anulación, y no son parte de la actividad que el local puede consultar.
     */
    public const MODULO_INTERNO = 'suscripciones';

    public const INTERNOS_SCOPE = 'sin_modulo_interno';

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
     * Con la sesión de alguien del local, lo interno no existe: así ninguna pantalla o consulta
     * futura puede mostrarlo por olvido. El superadmin y los comandos (sin sesión) lo ven todo.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(self::INTERNOS_SCOPE, function (Builder $builder): void {
            $usuario = Auth::user();

            if ($usuario instanceof User && ! $usuario->esSuperadmin()) {
                $builder->where($builder->qualifyColumn('modulo'), '!=', self::MODULO_INTERNO);
            }
        });
    }

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
