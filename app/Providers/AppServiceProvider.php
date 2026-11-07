<?php

namespace App\Providers;

use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\Reserva;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
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
        ]);

        RateLimiter::for('crear-pedido', function (Request $request): array {
            $responder = fn (): JsonResponse => response()->json([
                'message' => 'Se enviaron muchos pedidos desde tu conexión. Espera un momento e inténtalo otra vez.',
            ], 429);

            return [
                Limit::perMinute(5)->by('minuto:'.$request->ip())->response($responder),
                Limit::perHour(30)->by('hora:'.$request->ip())->response($responder),
            ];
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

            $view->with('panelRestaurante', $restaurante === null ? null : [
                'nombre' => $restaurante->nombre,
                'ciudad' => $restaurante->ciudad,
                'plan' => $restaurante->plan?->nombre,
                'abierto' => $restaurante->estaAbierto(),
                'iniciales' => $restaurante->iniciales(),
                'logo' => $restaurante->logoUrl(),
            ]);
        });
    }
}
