<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Modulos;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las rutas de un módulo apagado (en FOORA o en el local) no existen para quien las pide: 404.
 * Uso: ->middleware('modulo:mesas').
 */
class EnsureModuloActivo
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $modulo): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless(Modulos::activoPara($modulo, $user?->restaurante), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
