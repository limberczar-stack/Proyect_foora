<?php

namespace App\Policies;

use App\Models\Cuenta;
use App\Models\User;
use App\Services\GestionarCuentas;
use Illuminate\Auth\Access\Response;

/**
 * Cuentas de salón: admin, cajero y mesero abren y modifican cualquier cuenta del local.
 * Cerrar es de admin y cajero. Anular también, y el mesero solo una cuenta suya.
 * Cocina y repartidor no entran. Una cuenta de otro local nunca llega aquí: el scope la deja en 404.
 */
class CuentaPolicy
{
    public function before(User $user, string $ability): ?Response
    {
        return $user->esSuperadmin() || $user->restaurante_id === null
            ? Response::deny('Selecciona un restaurante.')
            : null;
    }

    /**
     * Abrir cuentas y ver las del local.
     */
    public function atender(User $user): bool
    {
        return in_array($user->rol, GestionarCuentas::ROLES_QUE_ATIENDEN, true);
    }

    /**
     * Agregar, quitar o mover mesas, fusionar y transferir.
     */
    public function update(User $user, Cuenta $cuenta): bool
    {
        return $this->atender($user) && $cuenta->restaurante_id === $user->restaurante_id;
    }

    public function cerrar(User $user, Cuenta $cuenta): bool
    {
        return in_array($user->rol, ['admin', 'cajero'], true) && $cuenta->restaurante_id === $user->restaurante_id;
    }

    public function anular(User $user, Cuenta $cuenta): bool
    {
        if ($cuenta->restaurante_id !== $user->restaurante_id) {
            return false;
        }

        return in_array($user->rol, ['admin', 'cajero'], true)
            || ($user->rol === 'mesero' && $cuenta->mesero_id === $user->id);
    }
}
