<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cuenta\CambiarPasswordRequest;
use App\Http\Requests\Cuenta\CerrarSesionesRequest;
use App\Models\User;
use App\Services\RegistrarActividad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * «Mi cuenta»: cambiar la propia contraseña y cerrar las sesiones de otros dispositivos.
 * Es lo único que ve quien entra con una contraseña temporal.
 */
class MiCuentaController extends Controller
{
    public function __construct(private RegistrarActividad $actividad) {}

    public function show(Request $request): View
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return view('panel.mi-cuenta', [
            'usuario' => $usuario,
            'otrasSesiones' => $this->otrasSesiones($request),
        ]);
    }

    public function password(CambiarPasswordRequest $request): JsonResponse|RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $eraTemporal = $usuario->debe_cambiar_password;

        $usuario->forceFill([
            'password' => $request->validated('password'),
            'debe_cambiar_password' => false,
        ])->save();

        $request->session()->regenerate();

        $this->actividad->registrar($usuario, 'usuarios', 'cambio_password', 'Cambió su contraseña.', $usuario,
            $eraTemporal ? ['debe_cambiar_password' => true] : null,
            $eraTemporal ? ['debe_cambiar_password' => false] : null);

        $mensaje = 'Se cambió tu contraseña.';

        if ($request->expectsJson()) {
            return response()->json(['mensaje' => $mensaje, 'redirect' => $eraTemporal ? route('panel.inicio') : null]);
        }

        return redirect()->route($eraTemporal ? 'panel.inicio' : 'panel.mi-cuenta')->with('status', $mensaje);
    }

    public function cerrarSesiones(CerrarSesionesRequest $request): JsonResponse|RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $cerradas = $this->otrasSesiones($request);

        $usuario->cerrarSesiones($request->session()->getId());

        $this->actividad->registrar($usuario, 'usuarios', 'cerro_otras_sesiones', 'Cerró sus sesiones en otros dispositivos.', $usuario);

        $mensaje = match ($cerradas) {
            0 => 'Listo: no había otras sesiones abiertas, y «recordarme» quedó anulado en otros dispositivos.',
            1 => 'Se cerró la sesión del otro dispositivo.',
            default => "Se cerraron {$cerradas} sesiones de otros dispositivos.",
        };

        if ($request->expectsJson()) {
            return response()->json(['mensaje' => $mensaje, 'otras_sesiones' => 0]);
        }

        return redirect()->route('panel.mi-cuenta')->with('status', $mensaje);
    }

    /**
     * Sesiones guardadas del usuario en otros dispositivos (solo con el driver de base de datos).
     */
    private function otrasSesiones(Request $request): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->count();
    }
}
