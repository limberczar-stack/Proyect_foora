<?php

namespace App\Services;

use App\Models\Area;
use App\Models\HorarioAtencion;
use App\Models\MetodoPago;
use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\RestauranteMetodoPago;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Alta de un local desde el superadmin, en una sola transacción: el local, su primer admin y sus
 * valores iniciales. Si algo falla no queda nada a medias.
 */
class CrearRestaurante
{
    private const HORA_APERTURA = '12:00';

    private const HORA_CIERRE = '22:00';

    private const AREA_INICIAL = 'Salón principal';

    public function __construct(private RegistrarActividad $actividad) {}

    /**
     * @param  array{nombre: string, slug: string, rubro_id: ?int, ciudad: string, telefono: ?string, direccion: ?string, plan_id: int, admin_nombre: string, admin_email: string}  $datos
     * @return array{0: Restaurante, 1: User, 2: string} el local, su admin y la contraseña temporal (solo para mostrarla una vez)
     */
    public function crear(User $actor, array $datos): array
    {
        $temporal = GestionarUsuarios::generarTemporal();

        try {
            [$restaurante, $admin] = DB::transaction(function () use ($actor, $datos, $temporal): array {
                $restaurante = Restaurante::create([
                    'nombre' => $datos['nombre'],
                    'slug' => $datos['slug'],
                    'rubro_id' => $datos['rubro_id'] ?? null,
                    'plan_id' => $datos['plan_id'],
                    'ciudad' => $datos['ciudad'],
                    'telefono' => $datos['telefono'] ?? null,
                    'direccion' => $datos['direccion'] ?? null,
                    'zona_horaria' => Restaurante::ZONA_POR_DEFECTO,
                    'estado_local' => 'cerrado',
                    'activo' => true,
                ]);

                $admin = $this->crearAdmin($restaurante, $datos, $temporal);
                $this->crearHorario($restaurante);
                $this->activarEfectivo($restaurante);
                Area::create(['restaurante_id' => $restaurante->id, 'nombre' => self::AREA_INICIAL, 'activa' => true, 'orden' => 0]);
                $suscripcion = $this->crearSuscripcion($restaurante, (int) $datos['plan_id']);

                $this->actividad->registrar($actor, 'suscripciones', 'alta_restaurante', "Dio de alta el local {$restaurante->nombre}.", $restaurante,
                    null,
                    [
                        'nombre' => $restaurante->nombre,
                        'slug' => $restaurante->slug,
                        'plan_id' => $restaurante->plan_id,
                        'admin' => ['name' => $admin->name, 'email' => $admin->email],
                        'suscripcion' => ['estado' => $suscripcion->estado, 'vence_el' => $suscripcion->vence_el->toDateString()],
                    ],
                    $restaurante);

                return [$restaurante, $admin];
            });
        } catch (UniqueConstraintViolationException) {
            // Dos altas a la vez con el mismo slug o correo: la validación no pudo verlo.
            throw ValidationException::withMessages(
                Restaurante::withTrashed()->where('slug', $datos['slug'])->exists()
                    ? ['slug' => 'Otro restaurante ya usa esta dirección.']
                    : ['admin_email' => 'Ya existe una cuenta con ese correo. Usa otro.'],
            );
        }

        return [$restaurante, $admin, $temporal];
    }

    /**
     * @param  array{admin_nombre: string, admin_email: string}  $datos
     */
    private function crearAdmin(Restaurante $restaurante, array $datos, string $temporal): User
    {
        $admin = new User([
            'name' => $datos['admin_nombre'],
            'email' => $datos['admin_email'],
            'password' => $temporal,
            'rol' => 'admin',
            'activo' => true,
        ]);
        $admin->forceFill(['restaurante_id' => $restaurante->id, 'debe_cambiar_password' => true])->save();

        return $admin;
    }

    private function crearHorario(Restaurante $restaurante): void
    {
        foreach (range(1, 7) as $dia) {
            HorarioAtencion::create([
                'restaurante_id' => $restaurante->id,
                'dia_semana' => $dia,
                'abre' => self::HORA_APERTURA,
                'cierra' => self::HORA_CIERRE,
                'cerrado' => false,
            ]);
        }
    }

    private function activarEfectivo(Restaurante $restaurante): void
    {
        $efectivo = MetodoPago::where('codigo', 'efectivo')->first()
            ?? throw new RuntimeException('Falta el método de pago Efectivo en el catálogo.');

        RestauranteMetodoPago::create([
            'restaurante_id' => $restaurante->id,
            'metodo_pago_id' => $efectivo->id,
            'activo' => true,
        ]);
    }

    private function crearSuscripcion(Restaurante $restaurante, int $planId): Suscripcion
    {
        $hoy = Carbon::parse($restaurante->fechaHoy(), 'UTC');

        // El plan se vuelve a comprobar aquí: pudo desactivarse después de validar el formulario.
        Plan::whereKey($planId)->firstOr(fn () => throw new RuntimeException('El plan elegido ya no existe.'));

        return Suscripcion::create([
            'restaurante_id' => $restaurante->id,
            'plan_id' => $planId,
            'estado' => 'prueba',
            'inicia_el' => $hoy->toDateString(),
            'vence_el' => $hoy->copy()->addDays(Suscripcion::DIAS_PRUEBA)->toDateString(),
            'precio_pactado' => 0,
        ]);
    }
}
