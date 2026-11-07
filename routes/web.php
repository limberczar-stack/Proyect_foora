<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Panel\CategoriaController;
use App\Http\Controllers\Panel\GrupoOpcionController;
use App\Http\Controllers\Panel\ProductoController;
use App\Http\Controllers\PedidoPublicoController;
use App\Http\Controllers\VitrinaController;
use App\Support\SlugsReservados;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'panel'])->prefix('panel')->name('panel.')->group(function () {
    Route::view('/', 'panel.inicio')->name('inicio');

    // Carta: los permisos por rol viven en ProductoPolicy, CategoriaProductoPolicy y GrupoOpcionPolicy.
    Route::prefix('carta')->name('carta.')->group(function () {
        Route::redirect('/', '/panel/carta/productos')->name('index');

        Route::resource('productos', ProductoController::class)
            ->except('show')
            ->parameters(['productos' => 'producto']);
        Route::patch('productos/{producto}/disponibilidad', [ProductoController::class, 'disponibilidad'])
            ->name('productos.disponibilidad');
        Route::patch('productos/{producto}/variantes/{variante}/disponibilidad', [ProductoController::class, 'disponibilidadVariante'])
            ->scopeBindings()
            ->name('productos.variantes.disponibilidad');

        Route::resource('grupos', GrupoOpcionController::class)
            ->except('show')
            ->parameters(['grupos' => 'grupo']);
        Route::patch('grupos/{grupo}/opciones/{opcion}/disponibilidad', [GrupoOpcionController::class, 'disponibilidadOpcion'])
            ->scopeBindings()
            ->name('grupos.opciones.disponibilidad');

        Route::get('categorias', [CategoriaController::class, 'index'])->name('categorias.index');
        Route::post('categorias', [CategoriaController::class, 'store'])->name('categorias.store');
        Route::put('categorias/{categoria}', [CategoriaController::class, 'update'])->name('categorias.update');
        Route::delete('categorias/{categoria}', [CategoriaController::class, 'destroy'])->name('categorias.destroy');
        Route::patch('categorias/{categoria}/estado', [CategoriaController::class, 'estado'])->name('categorias.estado');
        Route::patch('categorias/{categoria}/mover', [CategoriaController::class, 'mover'])->name('categorias.mover');
    });
});

/*
 * Vitrina pública. Debe seguir siendo lo último de este archivo: /{slug} es genérica.
 * Va sin sesión, cookies ni CSRF: cada visita desde WhatsApp crearía una fila en sessions, una
 * página abierta por horas daría 419, y validar el carrito no escribe nada.
 */
Route::withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
])->where(['slug' => SlugsReservados::patronRuta()])->group(function () {
    Route::get('/{slug}', [VitrinaController::class, 'show'])->name('vitrina.show');
    Route::post('/{slug}/carrito/validar', [VitrinaController::class, 'validarCarrito'])
        ->middleware('throttle:60,1')
        ->name('vitrina.carrito.validar');

    Route::post('/{slug}/pedido', [PedidoPublicoController::class, 'store'])
        ->middleware('throttle:crear-pedido')
        ->name('vitrina.pedido.store');
    Route::get('/{slug}/pedido/{codigo}', [PedidoPublicoController::class, 'show'])
        ->where('codigo', '[A-Za-z0-9]{1,64}')
        ->middleware('throttle:60,1')
        ->name('vitrina.pedido.seguimiento');
    Route::get('/{slug}/pedido/{codigo}/estado', [PedidoPublicoController::class, 'estado'])
        ->where('codigo', '[A-Za-z0-9]{1,64}')
        ->middleware('throttle:60,1')
        ->name('vitrina.pedido.estado');
});
