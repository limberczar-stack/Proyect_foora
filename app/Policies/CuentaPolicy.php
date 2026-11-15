<?php

namespace App\Policies;

use App\Models\Cuenta;
use App\Models\User;
use App\Services\GestionarCuentas;
use Illuminate\Auth\Access\Response;

/**
 * Cuentas de salón: admin, cajero y mesero abren y modifican cualquier cuenta del local, envían
 * rondas, las sirven y piden la precuenta. Cerrar es de admin y cajero, y también anular líneas o
 * rondas. Anular la cuenta: admin y cajero; el mesero solo una cuenta suya sin pedidos vivos.
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

    /**
     * Marcar «Servido» una ronda lista.
     */
    public function servir(User $user, Cuenta $cuenta): bool
    {
        return $this->update($user, $cuenta);
    }

    public function pedirPrecuenta(User $user, Cuenta $cuenta): bool
    {
        return $this->update($user, $cuenta);
    }

    /**
     * Anular una línea ya enviada o cancelar una ronda entera.
     */
    public function corregirRondas(User $user, Cuenta $cuenta): bool
    {
        return $this->cerrar($user, $cuenta);
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

        if (in_array($user->rol, ['admin', 'cajero'], true)) {
            return true;
        }

        return $user->rol === 'mesero'
            && $cuenta->mesero_id === $user->id
            && ! $cuenta->pedidos()->where('estado', '!=', 'cancelado')->exists();
    }
}
