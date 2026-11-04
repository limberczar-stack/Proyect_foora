<?php

namespace App\Policies;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Carta: el admin gestiona los productos; el cajero ve la carta y cambia la disponibilidad.
 */
class ProductoPolicy
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

    public function update(User $user, Producto $producto): bool
    {
        return $user->rol === 'admin' && $this->esDeSuRestaurante($user, $producto);
    }

    public function delete(User $user, Producto $producto): bool
    {
        return $this->update($user, $producto);
    }

    public function cambiarDisponibilidad(User $user, Producto $producto): bool
    {
        return in_array($user->rol, ['admin', 'cajero'], true) && $this->esDeSuRestaurante($user, $producto);
    }

    private function esDeSuRestaurante(User $user, Producto $producto): bool
    {
        return $producto->restaurante_id === $user->restaurante_id;
    }
}
