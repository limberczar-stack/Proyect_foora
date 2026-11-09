<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Local fuera de operación (Restaurante::operativo()): el panel queda para ver.
 * Se deja solo lo que permite terminar lo que está en curso: los pedidos existentes,
 * abrir o cerrar el local, y la propia cuenta. Todo lo demás que escribe, se rechaza.
 */
class EnsureLocalOperativo
{
    /**
     * @var list<string>
     */
    private const ESCRITURAS_PERMITIDAS = [
        'panel.pedidos.estado',
        'panel.pedidos.pago',
        'panel.estado-local',
        'panel.mi-cuenta.password',
        'panel.mi-cuenta.sesiones',
    ];

    public const MENSAJE = 'Tu local está fuera de operación: solo puedes ver la información y terminar los pedidos en curso. Contacta a FOORA.';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        $restaurante = $user?->restaurante;

        if (
            $restaurante === null
            || $request->isMethodSafe()
            || $request->routeIs(self::ESCRITURAS_PERMITIDAS)
            || $restaurante->operativo()
        ) {
            return $next($request);
        }

        return $request->expectsJson()
            ? response()->json(['message' => self::MENSAJE], Response::HTTP_FORBIDDEN)
            : back()->with('error', self::MENSAJE);
    }
}
