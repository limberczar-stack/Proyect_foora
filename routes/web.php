<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Panel\CategoriaController;
use App\Http\Controllers\Panel\ProductoController;
use Illuminate\Support\Facades\Route;

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

    // Carta: los permisos por rol viven en ProductoPolicy y CategoriaProductoPolicy.
    Route::prefix('carta')->name('carta.')->group(function () {
        Route::redirect('/', '/panel/carta/productos')->name('index');

        Route::resource('productos', ProductoController::class)
            ->except('show')
            ->parameters(['productos' => 'producto']);
        Route::patch('productos/{producto}/disponibilidad', [ProductoController::class, 'disponibilidad'])
            ->name('productos.disponibilidad');

        Route::get('categorias', [CategoriaController::class, 'index'])->name('categorias.index');
        Route::post('categorias', [CategoriaController::class, 'store'])->name('categorias.store');
        Route::put('categorias/{categoria}', [CategoriaController::class, 'update'])->name('categorias.update');
        Route::delete('categorias/{categoria}', [CategoriaController::class, 'destroy'])->name('categorias.destroy');
        Route::patch('categorias/{categoria}/estado', [CategoriaController::class, 'estado'])->name('categorias.estado');
        Route::patch('categorias/{categoria}/mover', [CategoriaController::class, 'mover'])->name('categorias.mover');
    });
});
