<?php

namespace App\Policies;

use App\Models\GrupoOpcion;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Carta: el admin gestiona los grupos; el cajero los ve y cambia la disponibilidad de sus opciones.
 */
class GrupoOpcionPolicy
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

    public function update(User $user, GrupoOpcion $grupo): bool
    {
        return $user->rol === 'admin' && $this->esDeSuRestaurante($user, $grupo);
    }

    public function delete(User $user, GrupoOpcion $grupo): bool
    {
        return $this->update($user, $grupo);
    }

    public function cambiarDisponibilidad(User $user, GrupoOpcion $grupo): bool
    {
        return in_array($user->rol, ['admin', 'cajero'], true) && $this->esDeSuRestaurante($user, $grupo);
    }

    private function esDeSuRestaurante(User $user, GrupoOpcion $grupo): bool
    {
        return $grupo->restaurante_id === $user->restaurante_id;
    }
}
