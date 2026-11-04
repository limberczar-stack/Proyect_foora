<?php

namespace App\Policies;

use App\Models\CategoriaProducto;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Carta: el admin gestiona las categorías; el cajero solo las ve.
 */
class CategoriaProductoPolicy
{
    /**
     * La carta es de un restaurante: el superadmin (sin restaurante) no entra por ahora.
     */
    public function before(User $user, string $ability): ?Response
    {
        return $user->esSuperadmin() || $user->restaurante_id === null
            ? Response::deny('Selecciona un restaurante.')
            : null;
    }

    public function viewAny(User $user): bool
    {
        return in_array($user->rol, ['admin', 'cajero'], true);
    }

    public function create(User $user): bool
    {
        return $user->rol === 'admin';
    }

    public function update(User $user, CategoriaProducto $categoria): bool
    {
        return $user->rol === 'admin' && $categoria->restaurante_id === $user->restaurante_id;
    }

    public function delete(User $user, CategoriaProducto $categoria): bool
    {
        return $this->update($user, $categoria);
    }
}
