<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Usuarios del local: solo el admin los gestiona, y solo los de su propio restaurante.
 * Un usuario de otro local nunca llega aquí: el controlador lo busca dentro del local y responde 404.
 */
class UserPolicy
{
    public function before(User $user, string $ability): ?Response
    {
        return $user->esSuperadmin() || $user->restaurante_id === null
            ? Response::deny('Selecciona un restaurante.')
            : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->rol === 'admin';
    }

    public function create(User $user): bool
    {
        return $user->rol === 'admin';
    }

    public function update(User $user, User $usuario): bool
    {
        return $user->rol === 'admin'
            && $usuario->restaurante_id === $user->restaurante_id
            && $usuario->esPersonal();
    }
}
