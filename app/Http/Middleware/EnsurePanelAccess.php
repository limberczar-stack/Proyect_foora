<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Solo deja entrar al panel a personal activo con restaurante activo, o al superadmin.
 * Va después de `auth`. Una cuenta desactivada con la sesión abierta se cierra.
 */
class EnsurePanelAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if (! $user->activo) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Tu cuenta fue desactivada. Pide al administrador del local que la reactive.');
        }

        abort_unless($user->puedeEntrarAlPanel(), Response::HTTP_FORBIDDEN, 'No tienes acceso al panel del restaurante.');

        return $next($request);
    }
}
