<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Mesa;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Áreas y mesas del local (fase A del salón).
 *
 * Reglas que se cumplen aquí, dentro de transacciones con el restaurante bloqueado
 * (dos admins a la vez no pueden pasar el límite del plan ni repetir un código):
 * - Las mesas activas no pasan planes.max_mesas, si el plan tiene límite. Cuentan todas las activas,
 *   aunque su área esté desactivada.
 * - El código es único entre las mesas activas. Se valida antes y, si otra petición gana la carrera,
 *   el índice uq_mesa_activa lo rechaza y se responde con el mismo error de validación.
 * - A mano solo se pasa entre disponible y fuera de servicio; ocupada sale de las cuentas (GestionarCuentas)
 *   y reservada saldrá de las reservas. Una mesa ocupada no se pone fuera de servicio ni se desactiva,
 *   y un área con mesas ocupadas no se desactiva.
 * - No se borran mesas: se desactivan. Un área solo se elimina si no tiene ninguna mesa.
 */
class GestionarMesas
{
    /** Máximo de mesas en una sola acción "Agregar varias". */
    public const MAXIMO_POR_LOTE = 50;

    public function __construct(private RegistrarActividad $actividad) {}

    /* ---------------------------------------------------------------------- */
    /* Áreas */
    /* ---------------------------------------------------------------------- */

    /**
     * @param  array{nombre: string}  $datos
     */
    public function crearArea(User $actor, array $datos): Area
    {
        return $this->conNombreDeAreaUnico(fn (): Area => DB::transaction(function () use ($actor, $datos): Area {
            $restaurante = $this->bloquearRestaurante($actor);

            $area = new Area(['nombre' => $datos['nombre'], 'activa' => true]);
            $area->forceFill([
                'restaurante_id' => $restaurante->id,
                'orden' => (int) Area::where('restaurante_id', $restaurante->id)->max('orden') + 1,
            ])->save();

            $this->actividad->registrar($actor, 'mesas', 'creo_area', "Creó el área {$area->nombre}.", $area, null, $this->fotoArea($area));

            return $area;
        }));
    }

    /**
     * @param  array{nombre: string}  $datos
     */
    public function actualizarArea(User $actor, Area $area, array $datos): Area
    {
        return $this->conNombreDeAreaUnico(fn (): Area => DB::transaction(function () use ($actor, $area, $datos): Area {
            $this->bloquearRestaurante($actor);
            $area->refresh();

            $antes = $this->fotoArea($area);
            $area->fill(['nombre' => $datos['nombre']])->save();

            [$cambiosAntes, $cambiosDespues] = RegistrarActividad::diferencias($antes, $this->fotoArea($area));

            if ($cambiosDespues !== []) {
                $this->actividad->registrar($actor, 'mesas', 'edito_area', "Editó el área {$area->nombre}.", $area, $cambiosAntes, $cambiosDespues);
            }

            return $area;
        }));
    }

    public function cambiarActivaArea(User $actor, Area $area, bool $activa): Area
    {
        return DB::transaction(function () use ($actor, $area, $activa): Area {
            $this->bloquearRestaurante($actor);
            $area->refresh();

            if ($area->activa === $activa) {
                return $area;
            }

            if (! $activa) {
                $ocupadas = Mesa::where('area_id', $area->id)->where('estado', 'ocupada')->count();

                if ($ocupadas > 0) {
                    throw ValidationException::withMessages([
                        'area' => "No puedes desactivar «{$area->nombre}» porque tiene {$ocupadas} ".($ocupadas === 1 ? 'mesa ocupada' : 'mesas ocupadas').'. Cierra o mueve sus cuentas primero.',
                    ]);
                }
            }

            $area->forceFill(['activa' => $activa])->save();

            $this->actividad->registrar($actor, 'mesas', $activa ? 'activo_area' : 'desactivo_area',
                ($activa ? 'Activó' : 'Desactivó')." el área {$area->nombre}.", $area, ['activa' => ! $activa], ['activa' => $activa]);

            return $area;
        });
    }

    /**
     * @param  'subir'|'bajar'  $direccion
     */
    public function moverArea(User $actor, Area $area, string $direccion): void
    {
        DB::transaction(function () use ($actor, $area, $direccion): void {
            $restaurante = $this->bloquearRestaurante($actor);

            $this->intercambiar(Area::where('restaurante_id', $restaurante->id)->ordenadas()->get(), $area, $direccion);
        });
    }

    /**
     * Las mesas de un área no se borran con ella: si tiene alguna (aunque esté desactivada), no se elimina.
     */
    public function eliminarArea(User $actor, Area $area): void
    {
        DB::transaction(function () use ($actor, $area): void {
            $this->bloquearRestaurante($actor);

            $mesas = Mesa::where('area_id', $area->id)->count();

            if ($mesas > 0) {
                throw ValidationException::withMessages([
                    'area' => "No puedes eliminar «{$area->nombre}» porque tiene {$mesas} ".($mesas === 1 ? 'mesa' : 'mesas')
                        .' (contando las desactivadas). Muévelas a otra área o desactiva el área.',
                ]);
            }

            $this->actividad->registrar($actor, 'mesas', 'elimino_area', "Eliminó el área {$area->nombre}.", null, $this->fotoArea($area), null);
            $area->delete();
        });
    }

    /* ---------------------------------------------------------------------- */
    /* Mesas */
    /* ---------------------------------------------------------------------- */

    /**
     * @param  array{codigo: string, capacidad: int, area_id: int}  $datos
     */
    public function crearMesa(User $actor, array $datos): Mesa
    {
        return $this->conCodigoUnico(fn (): Mesa => DB::transaction(function () use ($actor, $datos): Mesa {
            $restaurante = $this->bloquearRestaurante($actor);
            $this->exigirCupo($restaurante, 1);
            $this->exigirCodigosLibres($restaurante, [$datos['codigo']]);

            $mesa = $this->nuevaMesa($restaurante, $datos['area_id'], $datos['codigo'], $datos['capacidad'], $this->siguienteOrden($datos['area_id']));

            $this->actividad->registrar($actor, 'mesas', 'creo_mesa', "Creó la mesa {$mesa->codigo}.", $mesa, null, $this->fotoMesa($mesa));

            return $mesa;
        }));
    }

    /**
     * Crea un rango de mesas (prefijo + número): o se crean todas, o ninguna.
     *
     * @param  array{area_id: int, desde: int, hasta: int, capacidad: int, prefijo: ?string}  $datos
     * @return Collection<int, Mesa>
     */
    public function agregarVarias(User $actor, array $datos): Collection
    {
        $codigos = self::codigosDelRango($datos['prefijo'] ?? null, $datos['desde'], $datos['hasta']);

        if (count($codigos) > self::MAXIMO_POR_LOTE) {
            throw ValidationException::withMessages(['hasta' => 'Puedes agregar hasta '.self::MAXIMO_POR_LOTE.' mesas a la vez.']);
        }

        return $this->conCodigoUnico(fn (): Collection => DB::transaction(function () use ($actor, $datos, $codigos): Collection {
            $restaurante = $this->bloquearRestaurante($actor);
            $this->exigirCupo($restaurante, count($codigos));
            $this->exigirCodigosLibres($restaurante, $codigos);

            $orden = $this->siguienteOrden($datos['area_id']);
            $mesas = collect($codigos)->map(fn (string $codigo, int $indice): Mesa => $this->nuevaMesa($restaurante, $datos['area_id'], $codigo, $datos['capacidad'], $orden + $indice));

            $this->actividad->registrar($actor, 'mesas', 'agrego_mesas',
                'Agregó '.$mesas->count().' mesas ('.$mesas->first()->codigo.' a '.$mesas->last()->codigo.').', null,
                null, ['area_id' => $datos['area_id'], 'capacidad' => $datos['capacidad'], 'codigos' => $codigos]);

            return $mesas;
        }), varias: true);
    }

    /**
     * @param  array{codigo: string, capacidad: int, area_id: int, estado?: string}  $datos
     */
    public function actualizarMesa(User $actor, Mesa $mesa, array $datos): Mesa
    {
        if (isset($datos['estado'])) {
            self::exigirEstadoManual($datos['estado']);
        }

        return $this->conCodigoUnico(fn (): Mesa => DB::transaction(function () use ($actor, $mesa, $datos): Mesa {
            $restaurante = $this->bloquearRestaurante($actor);
            $mesa->refresh();

            if ($mesa->activa) {
                $this->exigirCodigosLibres($restaurante, [$datos['codigo']], $mesa);
            }

            if (isset($datos['estado']) && $datos['estado'] !== $mesa->estado) {
                $this->exigirEstadoActualManual($mesa);
            }

            $antes = $this->fotoMesa($mesa);
            $mesa->fill([
                'codigo' => $datos['codigo'],
                'capacidad' => $datos['capacidad'],
                'estado' => $datos['estado'] ?? $mesa->estado,
            ]);

            if ((int) $datos['area_id'] !== (int) $mesa->area_id) {
                $mesa->forceFill(['area_id' => (int) $datos['area_id'], 'orden' => $this->siguienteOrden($datos['area_id'])]);
            }

            $mesa->save();

            [$cambiosAntes, $cambiosDespues] = RegistrarActividad::diferencias($antes, $this->fotoMesa($mesa));

            if ($cambiosDespues !== []) {
                $this->actividad->registrar($actor, 'mesas', 'edito_mesa', "Editó la mesa {$mesa->codigo}.", $mesa, $cambiosAntes, $cambiosDespues);
            }

            return $mesa;
        }));
    }

    public function cambiarEstadoMesa(User $actor, Mesa $mesa, string $estado): Mesa
    {
        self::exigirEstadoManual($estado);

        return DB::transaction(function () use ($actor, $mesa, $estado): Mesa {
            $this->bloquearRestaurante($actor);
            $mesa->refresh();

            if ($mesa->estado === $estado) {
                return $mesa;
            }

            $this->exigirEstadoActualManual($mesa);

            $antes = $mesa->estado;
            $mesa->forceFill(['estado' => $estado])->save();

            $this->actividad->registrar($actor, 'mesas', 'cambio_estado_mesa',
                $estado === 'fuera_servicio' ? "Marcó la mesa {$mesa->codigo} fuera de servicio." : "Volvió a poner en servicio la mesa {$mesa->codigo}.",
                $mesa, ['estado' => $antes], ['estado' => $estado]);

            return $mesa;
        });
    }

    /**
     * Reactivar exige cupo en el plan y que ninguna mesa activa use su código.
     */
    public function cambiarActivaMesa(User $actor, Mesa $mesa, bool $activa): Mesa
    {
        return $this->conCodigoUnico(fn (): Mesa => DB::transaction(function () use ($actor, $mesa, $activa): Mesa {
            $restaurante = $this->bloquearRestaurante($actor);
            $mesa->refresh();

            if ($mesa->activa === $activa) {
                return $mesa;
            }

            if ($activa) {
                $this->exigirCupo($restaurante, 1);
                $this->exigirCodigosLibres($restaurante, [$mesa->codigo], $mesa);
            } else {
                $this->exigirEstadoActualManual($mesa);
            }

            $mesa->forceFill(['activa' => $activa])->save();

            $this->actividad->registrar($actor, 'mesas', $activa ? 'activo_mesa' : 'desactivo_mesa',
                ($activa ? 'Activó' : 'Desactivó')." la mesa {$mesa->codigo}.", $mesa, ['activa' => ! $activa], ['activa' => $activa]);

            return $mesa;
        }));
    }

    /**
     * Sube o baja una posición dentro de su área.
     *
     * @param  'subir'|'bajar'  $direccion
     */
    public function moverMesa(User $actor, Mesa $mesa, string $direccion): void
    {
        DB::transaction(function () use ($actor, $mesa, $direccion): void {
            $this->bloquearRestaurante($actor);
            $mesa->refresh();

            $this->intercambiar(Mesa::where('area_id', $mesa->area_id)->ordenadas()->get(), $mesa, $direccion);
        });
    }

    /**
     * Mesas activas del local y el límite de su plan (null = sin límite).
     *
     * @return array{activas: int, limite: ?int}
     */
    public function cupo(Restaurante $restaurante): array
    {
        return [
            'activas' => Mesa::where('restaurante_id', $restaurante->id)->where('activa', true)->count(),
            'limite' => $restaurante->plan?->max_mesas,
        ];
    }

    /**
     * @return list<string>
     */
    public static function codigosDelRango(?string $prefijo, int $desde, int $hasta): array
    {
        return array_map(fn (int $numero): string => ($prefijo ?? '').$numero, range($desde, max($desde, $hasta)));
    }

    public static function exigirEstadoManual(string $estado): void
    {
        if (! in_array($estado, Mesa::ESTADOS_MANUALES, true)) {
            throw ValidationException::withMessages(['estado' => 'Solo puedes marcar una mesa como disponible o fuera de servicio.']);
        }
    }

    /* ---------------------------------------------------------------------- */
    /* Internos */
    /* ---------------------------------------------------------------------- */

    private function bloquearRestaurante(User $actor): Restaurante
    {
        return Restaurante::with('plan')->lockForUpdate()->findOrFail($actor->restaurante_id);
    }

    private function exigirCupo(Restaurante $restaurante, int $nuevas): void
    {
        ['activas' => $activas, 'limite' => $limite] = $this->cupo($restaurante);

        if ($limite === null || $activas + $nuevas <= $limite) {
            return;
        }

        $libres = max(0, $limite - $activas);
        $plan = "Tu plan {$restaurante->plan->nombre} permite hasta {$limite} mesas activas y ya tienes {$activas}";

        throw ValidationException::withMessages([
            'mesas' => $nuevas === 1 || $libres === 0
                ? "{$plan}. Desactiva una o mejora tu plan."
                : "{$plan}: puedes agregar {$libres} más.",
        ]);
    }

    /**
     * @param  list<string>  $codigos
     */
    private function exigirCodigosLibres(Restaurante $restaurante, array $codigos, ?Mesa $excepto = null): void
    {
        $ocupados = Mesa::where('restaurante_id', $restaurante->id)
            ->where('activa', true)
            ->when($excepto, fn ($query) => $query->whereKeyNot($excepto->id))
            ->whereIn(DB::raw('LOWER(codigo)'), array_map(mb_strtolower(...), $codigos))
            ->orderBy('codigo')
            ->pluck('codigo');

        if ($ocupados->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'codigo' => count($codigos) === 1
                ? "Ya tienes una mesa activa «{$ocupados->first()}». Usa otro nombre o número."
                : 'Ya tienes mesas activas con estos códigos: '.$ocupados->implode(', ').'. Cambia el rango o el prefijo.',
        ]);
    }

    private function exigirEstadoActualManual(Mesa $mesa): void
    {
        if (! in_array($mesa->estado, Mesa::ESTADOS_MANUALES, true)) {
            throw ValidationException::withMessages(['estado' => "La mesa {$mesa->codigo} está ".($mesa->estado === 'ocupada' ? 'ocupada' : 'reservada').': no puedes cambiarla ahora.']);
        }
    }

    private function nuevaMesa(Restaurante $restaurante, int $areaId, string $codigo, int $capacidad, int $orden): Mesa
    {
        $mesa = new Mesa(['codigo' => $codigo, 'capacidad' => $capacidad, 'estado' => 'disponible', 'activa' => true]);
        $mesa->forceFill(['restaurante_id' => $restaurante->id, 'area_id' => $areaId, 'orden' => $orden])->save();

        return $mesa;
    }

    private function siguienteOrden(int $areaId): int
    {
        $maximo = Mesa::where('area_id', $areaId)->max('orden');

        return $maximo === null ? 0 : (int) $maximo + 1;
    }

    /**
     * Intercambia el elemento con su vecino y deja el orden de todo el grupo en 0, 1, 2…
     *
     * @param  EloquentCollection<int, Area|Mesa>  $grupo
     * @param  'subir'|'bajar'  $direccion
     */
    private function intercambiar(EloquentCollection $grupo, Area|Mesa $elemento, string $direccion): void
    {
        $lista = $grupo->values()->all();
        $posicion = $grupo->values()->search(fn (Area|Mesa $item): bool => $item->is($elemento));

        if ($posicion === false) {
            return;
        }

        $destino = $direccion === 'subir' ? $posicion - 1 : $posicion + 1;

        if ($destino < 0 || $destino >= count($lista)) {
            return;
        }

        [$lista[$posicion], $lista[$destino]] = [$lista[$destino], $lista[$posicion]];

        foreach ($lista as $indice => $item) {
            if ($item->orden !== $indice) {
                $item->forceFill(['orden' => $indice])->save();
            }
        }
    }

    /**
     * Si otra petición ocupó el código entre la validación y el guardado, el índice lo rechaza:
     * se responde con un error de validación, nunca con un 500.
     *
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     */
    private function conCodigoUnico(callable $operacion, bool $varias = false): mixed
    {
        try {
            return $operacion();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'codigo' => $varias
                    ? 'Alguno de esos códigos ya lo usa otra mesa activa. Cambia el rango o el prefijo.'
                    : 'Ese nombre o número ya lo usa otra mesa activa. Usa otro.',
            ]);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     */
    private function conNombreDeAreaUnico(callable $operacion): mixed
    {
        try {
            return $operacion();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nombre' => 'Ya tienes un área con ese nombre.']);
        }
    }

    /**
     * @return array{nombre: string, activa: bool}
     */
    private function fotoArea(Area $area): array
    {
        return ['nombre' => $area->nombre, 'activa' => (bool) $area->activa];
    }

    /**
     * @return array{codigo: string, capacidad: int, area_id: int, estado: string, activa: bool}
     */
    private function fotoMesa(Mesa $mesa): array
    {
        return [
            'codigo' => $mesa->codigo,
            'capacidad' => (int) $mesa->capacidad,
            'area_id' => (int) $mesa->area_id,
            'estado' => (string) $mesa->estado,
            'activa' => (bool) $mesa->activa,
        ];
    }
}
