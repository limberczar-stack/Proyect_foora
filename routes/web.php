<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Panel\CategoriaController;
use App\Http\Controllers\Panel\ConfiguracionController;
use App\Http\Controllers\Panel\EstadoLocalController;
use App\Http\Controllers\Panel\GrupoOpcionController;
use App\Http\Controllers\Panel\MiCuentaController;
use App\Http\Controllers\Panel\MiSuscripcionController;
use App\Http\Controllers\Panel\PedidoController;
use App\Http\Controllers\Panel\ProductoController;
use App\Http\Controllers\Panel\Superadmin\RestauranteController;
use App\Http\Controllers\Panel\Superadmin\ResumenController;
use App\Http\Controllers\Panel\Superadmin\SuscripcionController;
use App\Http\Controllers\Panel\UsuarioController;
use App\Http\Controllers\PedidoPublicoController;
use App\Http\Controllers\VitrinaController;
use App\Support\SlugsReservados;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
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

Route::middleware(['auth', 'panel', 'local.operativo'])->prefix('panel')->name('panel.')->group(function () {
    // El superadmin no tiene local: su inicio es el resumen de FOORA.
    Route::get('/', fn (Request $request) => $request->user()->esSuperadmin()
        ? redirect()->route('panel.superadmin.resumen')
        : view('panel.inicio'))->name('inicio');

    // Suscripción del local: solo para consultar.
    Route::get('mi-suscripcion', MiSuscripcionController::class)->name('suscripcion');

    // Sección del equipo de FOORA. {restaurante} se resuelve después de comprobar el permiso (AppServiceProvider).
    Route::middleware('can:superadmin')->prefix('superadmin')->name('superadmin.')->group(function () {
        Route::get('/', ResumenController::class)->name('resumen');

        Route::get('restaurantes', [RestauranteController::class, 'index'])->name('restaurantes.index');
        Route::get('restaurantes/crear', [RestauranteController::class, 'create'])->name('restaurantes.create');
        Route::post('restaurantes', [RestauranteController::class, 'store'])
            ->middleware('throttle:alta-restaurante')
            ->name('restaurantes.store');

        Route::whereNumber('restaurante')->prefix('restaurantes/{restaurante}')->name('restaurantes.')->group(function () {
            Route::get('/', [RestauranteController::class, 'show'])->name('show');
            Route::put('/', [RestauranteController::class, 'update'])->name('update');
            Route::put('notas', [RestauranteController::class, 'notas'])->name('notas');
            Route::put('plan', [SuscripcionController::class, 'plan'])->name('plan');
            Route::post('pagos', [SuscripcionController::class, 'registrarPago'])->name('pagos.store');
            Route::patch('pagos/{pago}/anular', [SuscripcionController::class, 'anularPago'])->whereNumber('pago')->name('pagos.anular');
            Route::patch('cancelar', [SuscripcionController::class, 'cancelar'])->name('cancelar');
            Route::patch('suspender', [SuscripcionController::class, 'suspender'])->name('suspender');
            Route::patch('reactivar', [SuscripcionController::class, 'reactivar'])->name('reactivar');
        });
    });

    // Mi cuenta: lo único accesible con una contraseña temporal (EnsurePanelAccess).
    Route::get('mi-cuenta', [MiCuentaController::class, 'show'])->name('mi-cuenta');
    Route::put('mi-cuenta/password', [MiCuentaController::class, 'password'])
        ->middleware('throttle:cambiar-password')
        ->name('mi-cuenta.password');
    Route::delete('mi-cuenta/sesiones', [MiCuentaController::class, 'cerrarSesiones'])
        ->middleware('throttle:cambiar-password')
        ->name('mi-cuenta.sesiones');

    // Estado del local (interruptor de la barra lateral): admin y cajero, según RestaurantePolicy.
    Route::patch('estado-local', [EstadoLocalController::class, 'update'])->name('estado-local');

    // Configuración: solo el admin, según RestaurantePolicy y UserPolicy.
    Route::prefix('configuracion')->name('configuracion.')->group(function () {
        Route::redirect('/', '/panel/configuracion/general')->name('index');

        foreach (['general', 'horario', 'pago', 'pedidos', 'notificaciones'] as $seccion) {
            Route::get($seccion, [ConfiguracionController::class, $seccion])->name($seccion);
            Route::put($seccion, [ConfiguracionController::class, 'actualizar'.ucfirst($seccion)])->name($seccion.'.update');
        }

        Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios');
        Route::post('usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
        Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->whereNumber('usuario')->name('usuarios.update');
        Route::patch('usuarios/{usuario}/estado', [UsuarioController::class, 'estado'])->whereNumber('usuario')->name('usuarios.estado');
        Route::post('usuarios/{usuario}/password', [UsuarioController::class, 'restablecer'])->whereNumber('usuario')->name('usuarios.password');
    });

    // Pedidos: los permisos por rol viven en PedidoPolicy.
    Route::get('pedidos', [PedidoController::class, 'index'])->name('pedidos.index');
    Route::get('pedidos/novedades', [PedidoController::class, 'novedades'])->name('pedidos.novedades');
    Route::get('pedidos/{pedido}', [PedidoController::class, 'show'])->whereNumber('pedido')->name('pedidos.show');
    Route::patch('pedidos/{pedido}/estado', [PedidoController::class, 'estado'])->whereNumber('pedido')->name('pedidos.estado');
    Route::patch('pedidos/{pedido}/pago', [PedidoController::class, 'pago'])->whereNumber('pedido')->name('pedidos.pago');

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
