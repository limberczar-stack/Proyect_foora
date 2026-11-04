<?php

namespace App\Providers;

use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\Reserva;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
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
    }
}
