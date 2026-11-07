<?php

namespace App\Policies;

use App\Models\Pedido;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Pedidos: admin y cajero hacen todo. Cocina solo ve lo que está en preparación o listo,
 * solo puede marcarlo listo y no ve datos de contacto ni pagos. Mesero y repartidor no entran.
 */
class PedidoPolicy
{
    /** Estados que ve la cocina. */
    public const ESTADOS_COCINA = ['en_preparacion', 'listo'];

    /**
     * Los pedidos son de un restaurante: el superadmin (sin restaurante) no entra por ahora.
     */
    public function before(User $user, string $ability): ?Response
    {
        return $user->esSuperadmin() || $user->restaurante_id === null
            ? Response::deny('Selecciona un restaurante.')
            : null;
    }

    public function viewAny(User $user): bool
    {
        return in_array($user->rol, ['admin', 'cajero', 'cocina'], true);
    }

    public function view(User $user, Pedido $pedido): bool
    {
        if (! $this->esDeSuRestaurante($user, $pedido)) {
            return false;
        }

        return $this->gestiona($user) || ($user->rol === 'cocina' && in_array($pedido->estado, self::ESTADOS_COCINA, true));
    }

    public function cambiarEstado(User $user, Pedido $pedido, string $destino): bool
    {
        if (! $this->esDeSuRestaurante($user, $pedido)) {
            return false;
        }

        return $this->gestiona($user) || ($user->rol === 'cocina' && $pedido->estado === 'en_preparacion' && $destino === 'listo');
    }

    public function revisarPago(User $user, Pedido $pedido): bool
    {
        return $this->gestiona($user) && $this->esDeSuRestaurante($user, $pedido);
    }

    /**
     * Teléfono, dirección y botones de contacto.
     */
    public function verContacto(User $user, Pedido $pedido): bool
    {
        return $this->revisarPago($user, $pedido);
    }

    private function gestiona(User $user): bool
    {
        return in_array($user->rol, ['admin', 'cajero'], true);
    }

    private function esDeSuRestaurante(User $user, Pedido $pedido): bool
    {
        return $pedido->restaurante_id === $user->restaurante_id;
    }
}
