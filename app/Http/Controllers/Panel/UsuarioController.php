<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\UsuarioRequest;
use App\Models\User;
use App\Services\GestionarUsuarios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Usuarios del local (pestaña Usuarios de Configuración). Solo el admin.
 *
 * Las contraseñas temporales viajan solo en la respuesta JSON de la petición que las crea:
 * no se guardan en la sesión, en logs ni en actividad_log, y la respuesta no se cachea.
 */
class UsuarioController extends Controller
{
    public function __construct(private GestionarUsuarios $gestion) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        /** @var User $actor */
        $actor = $request->user();
        $restaurante = $actor->restaurante->loadMissing('plan');

        $usuarios = User::query()
            ->where('restaurante_id', $restaurante->id)
            ->whereIn('rol', User::ROLES_PERSONAL)
            ->orderByDesc('activo')
            ->orderByRaw("CASE rol WHEN 'admin' THEN 0 WHEN 'cajero' THEN 1 WHEN 'mesero' THEN 2 WHEN 'cocina' THEN 3 ELSE 4 END")
            ->orderBy('name')
            ->get();

        return view('panel.configuracion.show', [
            'seccion' => 'usuarios',
            'restaurante' => $restaurante,
            'usuarios' => $usuarios,
            'cupo' => $this->gestion->cupo($restaurante),
            'roles' => array_intersect_key(User::ROL_ETIQUETAS, array_flip(User::ROLES_PERSONAL)),
        ]);
    }

    public function store(UsuarioRequest $request): JsonResponse
    {
        [$usuario, $temporal] = $this->gestion->crear($request->user(), $request->validated());

        return $this->conPassword([
            'mensaje' => "Se creó la cuenta de {$usuario->name}.",
            'usuario' => $this->paraJson($usuario),
            'password_temporal' => $temporal,
        ]);
    }

    public function update(UsuarioRequest $request): JsonResponse|RedirectResponse
    {
        $usuario = $this->gestion->actualizar($request->user(), $request->usuarioObjetivo(), $request->validated());

        return $this->responder($request, "Se guardaron los cambios de {$usuario->name}.", $usuario);
    }

    public function estado(Request $request, string $usuario): JsonResponse|RedirectResponse
    {
        $objetivo = $this->objetivo($request, $usuario);
        $activo = $request->validate(['activo' => ['required', 'boolean']], [
            'activo.required' => 'Indica si la cuenta queda activa.',
            'activo.boolean' => 'Indica si la cuenta queda activa.',
        ])['activo'];

        $objetivo = $this->gestion->cambiarActivo($request->user(), $objetivo, filter_var($activo, FILTER_VALIDATE_BOOLEAN));

        return $this->responder($request, $objetivo->activo
            ? "La cuenta de {$objetivo->name} está activa."
            : "La cuenta de {$objetivo->name} quedó desactivada y se cerraron sus sesiones.", $objetivo);
    }

    public function restablecer(Request $request, string $usuario): JsonResponse
    {
        $objetivo = $this->objetivo($request, $usuario);
        $temporal = $this->gestion->restablecerPassword($request->user(), $objetivo);

        return $this->conPassword([
            'mensaje' => "Se restableció la contraseña de {$objetivo->name} y se cerraron sus sesiones.",
            'usuario' => $this->paraJson($objetivo->refresh()),
            'password_temporal' => $temporal,
        ]);
    }

    /**
     * 403 si quien actúa no es admin; 404 si el usuario no es de su local.
     */
    private function objetivo(Request $request, string $id): User
    {
        Gate::authorize('viewAny', User::class);

        $objetivo = UsuarioRequest::buscarEnElLocal($request->user(), $id);
        Gate::authorize('update', $objetivo);

        return $objetivo;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function conPassword(array $datos): JsonResponse
    {
        return response()->json($datos)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    private function responder(Request $request, string $mensaje, User $usuario): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['mensaje' => $mensaje, 'usuario' => $this->paraJson($usuario)]);
        }

        return redirect()->route('panel.configuracion.usuarios')->with('status', $mensaje);
    }

    /**
     * @return array{id: int, name: string, email: ?string, telefono: ?string, rol: string, rol_etiqueta: string, activo: bool}
     */
    private function paraJson(User $usuario): array
    {
        return [
            'id' => $usuario->id,
            'name' => $usuario->name,
            'email' => $usuario->email,
            'telefono' => $usuario->telefono,
            'rol' => $usuario->rol,
            'rol_etiqueta' => $usuario->rolEtiqueta(),
            'activo' => (bool) $usuario->activo,
        ];
    }
}
