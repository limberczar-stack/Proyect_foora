<?php

namespace App\Policies;

use App\Models\Mesa;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Mesas: el admin las gestiona; cajero y mesero solo ven el mapa. Cocina y repartidor no entran.
 * Una mesa de otro local nunca llega aquí: el scope del restaurante la deja en 404.
 */
class MesaPolicy
{
    /** Roles que ven el mapa de mesas. */
    public const ROLES_QUE_VEN = ['admin', 'cajero', 'mesero'];

    /**
     * Las mesas son de un restaurante: el superadmin (sin restaurante) no entra.
     */
    public function before(User $user, string $ability): ?Response
    {
        return $user->esSuperadmin() || $user->restaurante_id === null
            ? Response::deny('Selecciona un restaurante.')
            : null;
    }

    public function viewAny(User $user): bool
    {
        return in_array($user->rol, self::ROLES_QUE_VEN, true);
    }

    /**
     * Gestionar el salón: crear, editar, ordenar, activar y desactivar mesas y áreas.
     */
    public function gestionar(User $user): bool
    {
        return $user->rol === 'admin';
    }

    public function update(User $user, Mesa $mesa): bool
    {
        return $user->rol === 'admin' && $mesa->restaurante_id === $user->restaurante_id;
    }
}
