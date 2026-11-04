<?php

namespace App\Providers;

use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\Reserva;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
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
