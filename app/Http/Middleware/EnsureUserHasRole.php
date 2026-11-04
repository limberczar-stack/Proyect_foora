<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a ciertos roles: ->middleware('rol:admin,cajero').
 * El superadmin de FOORA siempre pasa.
 */
class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_if($user === null, Response::HTTP_UNAUTHORIZED);

        if ($user->esSuperadmin() || in_array($user->rol, $roles, true)) {
            return $next($request);
        }

        abort(Response::HTTP_FORBIDDEN, 'Tu rol no tiene permiso para esta sección.');
    }
}
