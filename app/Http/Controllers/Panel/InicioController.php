<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ResumenInicio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * El inicio según el rol: el superadmin va a su resumen, la cocina a sus pedidos, el admin y el cajero
 * ven el resumen del local, y mesero y repartidor una página que explica que aún no tienen módulos.
 */
class InicioController extends Controller
{
    /** Roles que ven el resumen del local. */
    private const ROLES_CON_RESUMEN = ['admin', 'cajero'];

    public function __invoke(Request $request, ResumenInicio $resumen): View|RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        if ($usuario->esSuperadmin()) {
            return redirect()->route('panel.superadmin.resumen');
        }

        if ($usuario->rol === 'cocina') {
            return redirect()->route('panel.pedidos.index');
        }

        if (! in_array($usuario->rol, self::ROLES_CON_RESUMEN, true)) {
            return view('panel.sin-modulos');
        }

        return view('panel.inicio', [
            ...$resumen->para($usuario->restaurante, $usuario->can('configurar', $usuario->restaurante)),
            'primerNombre' => Str::before(trim($usuario->name), ' '),
        ]);
    }
}
