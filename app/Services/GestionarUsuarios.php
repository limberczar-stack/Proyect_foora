<?php

namespace App\Services;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Usuarios del local: alta, edición, activación y contraseñas temporales.
 *
 * Reglas que se cumplen aquí, dentro de transacciones con el restaurante bloqueado
 * (dos admins a la vez no pueden pasar el límite del plan ni dejar el local sin admin):
 * - Solo roles de personal; nunca superadmin ni cliente.
 * - Nadie se cambia el rol, se desactiva ni se restablece la contraseña a sí mismo desde aquí.
 * - Siempre queda al menos un admin activo.
 * - Los usuarios activos no pasan planes.max_usuarios, si el plan tiene límite.
 */
class GestionarUsuarios
{
    /** Sin letras ni números que se confundan al dictarlos (0/O, 1/l/I). */
    private const ALFABETO_TEMPORAL = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    private const LONGITUD_TEMPORAL = 12;

    public function __construct(private RegistrarActividad $actividad) {}

    /**
     * @param  array{name: string, email: string, telefono: ?string, rol: string}  $datos
     * @return array{0: User, 1: string} el usuario y su contraseña temporal (solo para mostrarla una vez)
     */
    public function crear(User $actor, array $datos): array
    {
        $this->exigirRolDePersonal($datos['rol']);
        $temporal = self::generarTemporal();

        $usuario = DB::transaction(function () use ($actor, $datos, $temporal): User {
            $this->exigirCupo($this->bloquearRestaurante($actor));

            $usuario = new User([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'telefono' => $datos['telefono'] ?? null,
                'rol' => $datos['rol'],
                'password' => $temporal,
                'activo' => true,
            ]);
            $usuario->forceFill(['restaurante_id' => $actor->restaurante_id, 'debe_cambiar_password' => true])->save();

            $this->actividad->registrar($actor, 'usuarios', 'creo_usuario', "Creó el usuario {$usuario->name} ({$usuario->rolEtiqueta()}).", $usuario,
                null, $this->foto($usuario));

            return $usuario;
        });

        return [$usuario, $temporal];
    }

    /**
     * @param  array{name: string, telefono: ?string, rol: string}  $datos
     */
    public function actualizar(User $actor, User $usuario, array $datos): User
    {
        $this->exigirRolDePersonal($datos['rol']);

        if ($usuario->is($actor) && $datos['rol'] !== $usuario->rol) {
            throw ValidationException::withMessages(['rol' => 'No puedes cambiar tu propio rol. Pide a otro administrador que lo haga.']);
        }

        return DB::transaction(function () use ($actor, $usuario, $datos): User {
            $this->bloquearRestaurante($actor);
            $usuario->refresh();

            if ($usuario->rol === 'admin' && $datos['rol'] !== 'admin' && $usuario->activo) {
                $this->exigirOtroAdminActivo($usuario, 'rol');
            }

            $antes = $this->foto($usuario);
            $usuario->fill([
                'name' => $datos['name'],
                'telefono' => $datos['telefono'] ?? null,
                'rol' => $datos['rol'],
            ])->save();

            [$cambiosAntes, $cambiosDespues] = RegistrarActividad::diferencias($antes, $this->foto($usuario));

            if ($cambiosDespues !== []) {
                $this->actividad->registrar($actor, 'usuarios', 'edito_usuario', "Editó el usuario {$usuario->name}.", $usuario, $cambiosAntes, $cambiosDespues);
            }

            return $usuario;
        });
    }

    public function cambiarActivo(User $actor, User $usuario, bool $activo): User
    {
        if ($usuario->is($actor) && ! $activo) {
            throw ValidationException::withMessages(['activo' => 'No puedes desactivar tu propia cuenta.']);
        }

        $usuario = DB::transaction(function () use ($actor, $usuario, $activo): User {
            $restaurante = $this->bloquearRestaurante($actor);
            $usuario->refresh();

            if ($usuario->activo === $activo) {
                return $usuario;
            }

            if ($activo) {
                $this->exigirCupo($restaurante);
            } elseif ($usuario->rol === 'admin') {
                $this->exigirOtroAdminActivo($usuario, 'activo');
            }

            $usuario->forceFill(['activo' => $activo])->save();

            if (! $activo) {
                $usuario->cerrarSesiones();
            }

            $this->actividad->registrar($actor, 'usuarios', $activo ? 'activo_usuario' : 'desactivo_usuario',
                ($activo ? 'Activó' : 'Desactivó')." el usuario {$usuario->name}.", $usuario,
                ['activo' => ! $activo], ['activo' => $activo]);

            return $usuario;
        });

        return $usuario;
    }

    /**
     * @return string la contraseña temporal nueva (solo para mostrarla una vez)
     */
    public function restablecerPassword(User $actor, User $usuario): string
    {
        if ($usuario->is($actor)) {
            throw ValidationException::withMessages(['usuario' => 'Para cambiar tu contraseña usa «Mi cuenta».']);
        }

        $temporal = self::generarTemporal();

        DB::transaction(function () use ($actor, $usuario, $temporal): void {
            $usuario->forceFill(['password' => $temporal, 'debe_cambiar_password' => true])->save();
            $usuario->cerrarSesiones();

            $this->actividad->registrar($actor, 'usuarios', 'restablecio_password',
                "Restableció la contraseña de {$usuario->name}.", $usuario,
                ['debe_cambiar_password' => false], ['debe_cambiar_password' => true]);
        });

        return $temporal;
    }

    /**
     * Usuarios activos del local y el límite de su plan (null = sin límite).
     *
     * @return array{activos: int, limite: ?int}
     */
    public function cupo(Restaurante $restaurante): array
    {
        return [
            'activos' => User::where('restaurante_id', $restaurante->id)->where('activo', true)->count(),
            'limite' => $restaurante->plan?->max_usuarios,
        ];
    }

    public static function generarTemporal(): string
    {
        $alfabeto = self::ALFABETO_TEMPORAL;
        $ultimo = strlen($alfabeto) - 1;

        do {
            $clave = '';

            for ($i = 0; $i < self::LONGITUD_TEMPORAL; $i++) {
                $clave .= $alfabeto[random_int(0, $ultimo)];
            }
        } while (! preg_match('/[A-Z]/', $clave) || ! preg_match('/[a-z]/', $clave) || ! preg_match('/\d/', $clave));

        return $clave;
    }

    private function bloquearRestaurante(User $actor): Restaurante
    {
        return Restaurante::with('plan')->lockForUpdate()->findOrFail($actor->restaurante_id);
    }

    private function exigirCupo(Restaurante $restaurante): void
    {
        ['activos' => $activos, 'limite' => $limite] = $this->cupo($restaurante);

        if ($limite !== null && $activos >= $limite) {
            throw ValidationException::withMessages([
                'usuario' => "Tu plan {$restaurante->plan->nombre} permite hasta {$limite} usuarios activos y ya tienes {$activos}. Desactiva uno o mejora tu plan.",
            ]);
        }
    }

    private function exigirOtroAdminActivo(User $usuario, string $campo): void
    {
        $otrosAdmins = User::where('restaurante_id', $usuario->restaurante_id)
            ->where('rol', 'admin')
            ->where('activo', true)
            ->whereKeyNot($usuario->id)
            ->count();

        if ($otrosAdmins === 0) {
            throw ValidationException::withMessages([$campo => 'El local debe tener al menos un administrador activo. Nombra a otro administrador antes.']);
        }
    }

    private function exigirRolDePersonal(string $rol): void
    {
        if (! in_array($rol, User::ROLES_PERSONAL, true)) {
            throw ValidationException::withMessages(['rol' => 'Elige un rol válido para el personal del local.']);
        }
    }

    /**
     * @return array{name: string, email: ?string, telefono: ?string, rol: string, activo: bool}
     */
    private function foto(User $usuario): array
    {
        return [
            'name' => $usuario->name,
            'email' => $usuario->email,
            'telefono' => $usuario->telefono,
            'rol' => $usuario->rol,
            'activo' => (bool) $usuario->activo,
        ];
    }
}
