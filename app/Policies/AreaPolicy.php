<?php

namespace App\Policies;

use App\Models\Area;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Áreas del salón: solo el admin las gestiona (ver MesaPolicy para quién ve el mapa).
 */
class AreaPolicy
{
    public function before(User $user, string $ability): ?Response
    {
        return $user->esSuperadmin() || $user->restaurante_id === null
            ? Response::deny('Selecciona un restaurante.')
            : null;
    }

    public function update(User $user, Area $area): bool
    {
        return $user->rol === 'admin' && $area->restaurante_id === $user->restaurante_id;
    }

    public function delete(User $user, Area $area): bool
    {
        return $this->update($user, $area);
    }
}
