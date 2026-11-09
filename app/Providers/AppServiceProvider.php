<?php

namespace App\Providers;

use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\Reserva;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\SuscripcionPago;
use App\Models\User;
use App\Policies\SuperadminPolicy;
use App\Support\ContactoFoora;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'pedido' => Pedido::class,
            'reserva' => Reserva::class,
            'producto' => Producto::class,
            'mesa' => Mesa::class,
            'promocion' => Promocion::class,
            'restaurante' => Restaurante::class,
            'user' => User::class,
            'suscripcion' => Suscripcion::class,
            'suscripcion_pago' => SuscripcionPago::class,
        ]);

        Gate::define('superadmin', [SuperadminPolicy::class, 'acceder']);

        // Solo el superadmin llega a estas rutas. El permiso va antes de buscar el local:
        // así nadie más puede saber con un 404 qué ids existen.
        Route::bind('restaurante', function (string $id): Restaurante {
            Gate::authorize('superadmin');

            return Restaurante::findOrFail($id);
        });

        RateLimiter::for('alta-restaurante', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) $request->user()?->id)
            ->response(fn () => response()->json(['message' => 'Hiciste muchas altas seguidas. Espera un minuto e inténtalo otra vez.'], 429)));

        RateLimiter::for('crear-pedido', function (Request $request): array {
            $responder = fn (): JsonResponse => response()->json([
                'message' => 'Se enviaron muchos pedidos desde tu conexión. Espera un momento e inténtalo otra vez.',
            ], 429);

            return [
                Limit::perMinute(5)->by('minuto:'.$request->ip())->response($responder),
                Limit::perHour(30)->by('hora:'.$request->ip())->response($responder),
            ];
        });

        // Cambiar la contraseña o cerrar sesiones: 6 intentos por minuto por usuario e IP.
        RateLimiter::for('cambiar-password', function (Request $request): Limit {
            $mensaje = 'Hiciste muchos intentos seguidos. Espera un minuto e inténtalo otra vez.';

            return Limit::perMinute(6)
                ->by(($request->user()?->id ?? 'invitado').'|'.$request->ip())
                ->response(fn () => $request->expectsJson()
                    ? response()->json(['message' => $mensaje], 429)
                    : back()->with('error', $mensaje));
        });

        // Páginas de error: el personal vuelve al panel; el público, a la portada.
        View::composer(['errors::*', 'errors.*'], function (ViewContract $view): void {
            $enPanel = request()->is('panel', 'panel/*');

            $view->with([
                'inicioUrl' => url($enPanel ? '/panel' : '/'),
                'inicioTexto' => $enPanel ? 'Ir al inicio del panel' : 'Ir a la portada',
            ]);
        });

        View::composer('layouts.panel', function (ViewContract $view): void {
            /** @var User|null $user */
            $user = Auth::user();
            $restaurante = $user?->restaurante?->loadMissing('plan');

            $view->with('panelUsuario', $user === null ? null : [
                'nombre' => $user->name,
                'rol' => $user->rolEtiqueta(),
                'iniciales' => $user->iniciales(),
            ]);

            // Badge del menú: pedidos que esperan ser aceptados.
            $view->with('navPedidosNuevos', $restaurante === null ? null : Pedido::query()
                ->where('restaurante_id', $restaurante->id)
                ->where('estado', 'nuevo')
                ->count());

            $view->with('panelRestaurante', $restaurante === null ? null : [
                'nombre' => $restaurante->nombre,
                'ciudad' => $restaurante->ciudad,
                'plan' => $restaurante->plan?->nombre,
                'abierto' => $restaurante->estaAbierto(),
                'iniciales' => $restaurante->iniciales(),
                'logo' => $restaurante->logoUrl(),
                'puedeCambiarEstado' => $user->can('cambiarEstado', $restaurante),
                'puedeConfigurar' => $user->can('configurar', $restaurante),
                'aviso' => $restaurante->avisoSuscripcion(),
                'contacto' => ContactoFoora::enlaces($restaurante->nombre),
            ]);

            $view->with('panelEsSuperadmin', (bool) $user?->esSuperadmin());
        });
    }
}
