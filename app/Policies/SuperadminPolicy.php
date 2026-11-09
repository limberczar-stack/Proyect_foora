<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Sección del equipo de FOORA: el superadmin ve y gestiona todos los locales; nadie más entra.
 * Se registra como la habilidad "superadmin" en AppServiceProvider.
 */
class SuperadminPolicy
{
    public function acceder(User $user): Response
    {
        return $user->esSuperadmin()
            ? Response::allow()
            : Response::deny('Esta sección es solo para el equipo de FOORA.');
    }
}
