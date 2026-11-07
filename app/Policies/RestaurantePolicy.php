<?php

namespace App\Policies;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Configuración del local: solo su admin la ve y la edita. Abrir o cerrar el local
 * también lo puede hacer el cajero, que es quien suele estar en caja.
 */
class RestaurantePolicy
{
    /**
     * La configuración es de un restaurante: el superadmin (sin restaurante) no entra por ahora.
     */
    public function before(User $user, string $ability): ?Response
    {
        return $user->esSuperadmin() || $user->restaurante_id === null
            ? Response::deny('Selecciona un restaurante.')
            : null;
    }

    public function configurar(User $user, Restaurante $restaurante): bool
    {
        return $user->rol === 'admin' && $this->esSuyo($user, $restaurante);
    }

    public function cambiarEstado(User $user, Restaurante $restaurante): bool
    {
        return in_array($user->rol, ['admin', 'cajero'], true) && $this->esSuyo($user, $restaurante);
    }

    private function esSuyo(User $user, Restaurante $restaurante): bool
    {
        return $restaurante->id === $user->restaurante_id;
    }
}
