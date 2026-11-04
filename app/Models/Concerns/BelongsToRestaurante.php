<?php

namespace App\Models\Concerns;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Aísla los datos por restaurante (multi-tenant).
 *
 * - Filtra las consultas por el restaurante_id del usuario autenticado.
 * - No filtra si no hay sesión, si el usuario es superadmin o si no pertenece
 *   a ningún restaurante (cliente final de la app).
 * - Asigna restaurante_id al crear, si no se envió explícitamente.
 *
 * @mixin Model
 */
trait BelongsToRestaurante
{
    public const string RESTAURANTE_SCOPE = 'restaurante';

    public static function bootBelongsToRestaurante(): void
    {
        static::addGlobalScope(self::RESTAURANTE_SCOPE, function (Builder $builder): void {
            $restauranteId = self::restauranteIdDelUsuarioActual();

            if ($restauranteId !== null) {
                $builder->where($builder->qualifyColumn('restaurante_id'), $restauranteId);
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('restaurante_id') !== null) {
                return;
            }

            $restauranteId = self::restauranteIdDelUsuarioActual();

            if ($restauranteId !== null) {
                $model->setAttribute('restaurante_id', $restauranteId);
            }
        });
    }

    /**
     * Restaurante del usuario autenticado, o null si no se debe filtrar.
     */
    protected static function restauranteIdDelUsuarioActual(): ?int
    {
        $user = Auth::user();

        if (! $user instanceof User || $user->rol === 'superadmin') {
            return null;
        }

        return $user->restaurante_id;
    }

    /**
     * @return BelongsTo<Restaurante, $this>
     */
    public function restaurante(): BelongsTo
    {
        return $this->belongsTo(Restaurante::class);
    }
}
