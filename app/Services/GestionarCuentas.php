<?php

namespace App\Services;

use App\Exceptions\RondasSinServir;
use App\Models\Cuenta;
use App\Models\Mesa;
use App\Models\OcupacionMesa;
use App\Models\Pedido;
use App\Models\PedidoHistorial;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Cuentas de salón (fase B1): abrir, cambiar sus mesas, fusionar, transferir, cerrar y anular.
 *
 * Cada operación es una transacción con la fila del restaurante bloqueada: las cuentas de un local
 * se atienden de una en una, así que el correlativo no tiene huecos ni duplicados y dos personas no
 * pueden ocupar la misma mesa ni interbloquearse al unir mesas cruzadas.
 *
 * - Quien actúa envía la version de la cuenta que veía; si cambió (o la mesa que veía libre ya no
 *   lo está), la operación falla con 409 en vez de pisar el cambio de otra persona.
 * - Invariante: una mesa está "ocupada" si y solo si tiene una ocupación abierta (salida_at nulo).
 *   Entrar o salir de una cuenta cambia mesas.estado en la misma transacción.
 * - Los índices uq_ocu_mesa_abierta y uq_cuenta_num son la última defensa: si alguna vez saltan,
 *   se responde 409, nunca 500.
 * - Nada se borra: cerrar, anular y fusionar dejan la cuenta y sus ocupaciones como historial.
 */
class GestionarCuentas
{
    /** Mesas que puede tener una cuenta a la vez (al abrir, al agregar y al fusionar). */
    public const MESAS_MAXIMO = 10;

    /** El plano resalta las cuentas que llevan abiertas más de estas horas. No se cierran solas. */
    public const HORAS_PARA_RESALTAR = 6;

    /**
     * Roles que atienden el salón: abren cuentas y pueden recibir una transferida.
     *
     * @var list<string>
     */
    public const ROLES_QUE_ATIENDEN = ['admin', 'cajero', 'mesero'];

    public const MENSAJE_DESACTUALIZADA = 'La cuenta cambió mientras la veías. Actualizamos el plano.';

    public const MENSAJE_YA_NO_ABIERTA = 'Esta cuenta ya no está abierta. Actualizamos el plano.';

    public const MENSAJE_INDICE = 'Otra persona ocupó esa mesa o abrió una cuenta al mismo tiempo. Actualizamos el plano; inténtalo otra vez.';

    public const MENSAJE_TOTAL_CAMBIO = 'El total de la cuenta cambió mientras la veías. Revísalo antes de cerrarla.';

    public function __construct(private RegistrarActividad $actividad) {}

    /**
     * @param  list<int>  $mesaIds  de 1 a 10 mesas, todas disponibles, activas y de un área activa
     *
     * @throws ConflictHttpException si alguna mesa ya no está disponible
     */
    public function abrir(User $actor, array $mesaIds, int $comensales, ?string $nota = null): Cuenta
    {
        $mesaIds = array_values(array_unique(array_map(intval(...), $mesaIds)));

        if ($mesaIds === [] || count($mesaIds) > self::MESAS_MAXIMO) {
            throw ValidationException::withMessages(['mesas' => 'Una cuenta lleva de 1 a '.self::MESAS_MAXIMO.' mesas.']);
        }

        self::exigirComensales($comensales);

        return $this->protegida(fn (): Cuenta => DB::transaction(function () use ($actor, $mesaIds, $comensales, $nota): Cuenta {
            $restaurante = $this->bloquearRestaurante($actor);
            $mesas = $this->mesasDisponibles($restaurante, $mesaIds);
            $ahora = now();

            $cuenta = new Cuenta(['comensales' => $comensales, 'nota' => $nota !== null && trim($nota) !== '' ? trim($nota) : null]);
            $cuenta->forceFill([
                'restaurante_id' => $restaurante->id,
                'numero' => (int) Cuenta::where('restaurante_id', $restaurante->id)->max('numero') + 1,
                'estado' => 'abierta',
                'version' => 1,
                'mesero_id' => $actor->id,
                'abierta_at' => $ahora,
            ])->save();

            foreach ($mesas as $mesa) {
                $this->ocupar($cuenta, $mesa, $ahora);
            }

            $this->actividad->registrar($actor, 'mesas', 'abrio_cuenta',
                "Abrió la cuenta #{$cuenta->numero} en ".$this->etiquetaMesas($mesas->pluck('codigo')->all()).'.', $cuenta,
                null, ['mesas' => $mesas->pluck('codigo')->all(), 'comensales' => $comensales, 'mesero_id' => $actor->id]);

            return $cuenta;
        }));
    }

    public function agregarMesa(User $actor, Cuenta $cuenta, int $version, int $mesaId): Cuenta
    {
        return $this->protegida(fn (): Cuenta => DB::transaction(function () use ($actor, $cuenta, $version, $mesaId): Cuenta {
            $restaurante = $this->bloquearRestaurante($actor);
            $cuenta = $this->vigente($restaurante, $cuenta, $version);

            if ($cuenta->ocupacionesAbiertas()->count() >= self::MESAS_MAXIMO) {
                throw ValidationException::withMessages(['mesa_id' => 'Una cuenta lleva hasta '.self::MESAS_MAXIMO.' mesas.']);
            }

            $mesa = $this->mesasDisponibles($restaurante, [$mesaId])->first();
            $this->ocupar($cuenta, $mesa, now());
            $this->subirVersion($cuenta);

            $this->actividad->registrar($actor, 'mesas', 'agrego_mesa_cuenta', "Agregó la mesa {$mesa->codigo} a la cuenta #{$cuenta->numero}.", $cuenta,
                null, ['mesa' => $mesa->codigo]);

            return $cuenta;
        }));
    }

    /**
     * La cuenta debe conservar al menos una mesa.
     */
    public function quitarMesa(User $actor, Cuenta $cuenta, int $version, int $mesaId): Cuenta
    {
        return DB::transaction(function () use ($actor, $cuenta, $version, $mesaId): Cuenta {
            $restaurante = $this->bloquearRestaurante($actor);
            $cuenta = $this->vigente($restaurante, $cuenta, $version);
            $ocupaciones = $cuenta->ocupacionesAbiertas()->with('mesa')->get();
            $ocupacion = $this->ocupacionDe($ocupaciones, $mesaId);

            if ($ocupaciones->count() <= 1) {
                throw ValidationException::withMessages(['mesa_id' => 'La cuenta debe conservar al menos una mesa. Si ya se fueron, ciérrala o anúlala.']);
            }

            $this->desocupar($ocupacion, now());
            $this->subirVersion($cuenta);

            $this->actividad->registrar($actor, 'mesas', 'quito_mesa_cuenta', "Quitó la mesa {$ocupacion->mesa->codigo} de la cuenta #{$cuenta->numero}.", $cuenta,
                ['mesa' => $ocupacion->mesa->codigo], null);

            return $cuenta;
        });
    }

    /**
     * Cambia una mesa de la cuenta por otra que esté disponible.
     */
    public function moverMesa(User $actor, Cuenta $cuenta, int $version, int $desdeMesaId, int $haciaMesaId): Cuenta
    {
        return $this->protegida(fn (): Cuenta => DB::transaction(function () use ($actor, $cuenta, $version, $desdeMesaId, $haciaMesaId): Cuenta {
            $restaurante = $this->bloquearRestaurante($actor);
            $cuenta = $this->vigente($restaurante, $cuenta, $version);
            $ocupacion = $this->ocupacionDe($cuenta->ocupacionesAbiertas()->with('mesa')->get(), $desdeMesaId);
            $destino = $this->mesasDisponibles($restaurante, [$haciaMesaId])->first();
            $ahora = now();

            $this->desocupar($ocupacion, $ahora);
            $this->ocupar($cuenta, $destino, $ahora);
            $this->subirVersion($cuenta);

            $this->actividad->registrar($actor, 'mesas', 'movio_cuenta',
                "Movió la cuenta #{$cuenta->numero} de la mesa {$ocupacion->mesa->codigo} a la {$destino->codigo}.", $cuenta,
                ['mesa' => $ocupacion->mesa->codigo], ['mesa' => $destino->codigo]);

            return $cuenta;
        }));
    }

    /**
     * Las mesas y los pedidos de $otra pasan a $principal, y $otra queda "fusionada" (no se borra).
     * Los comensales se suman y se conserva el mesero de la principal.
     *
     * Rondas: las de $otra se agregan al final, en su orden (si la principal tenía 1 y 2, y la otra
     * 1 a 3, quedan 3 a 5). Las de la principal no cambian de número: la cocina ya las vio. Cada
     * pedido movido deja en su historial qué ronda y qué cuenta era antes.
     *
     * @throws ConflictHttpException si cualquiera de las dos cambió desde que se vieron
     */
    public function fusionar(User $actor, Cuenta $principal, int $version, Cuenta $otra, int $versionOtra): Cuenta
    {
        if ($principal->is($otra)) {
            throw ValidationException::withMessages(['otra_cuenta_id' => 'Elige otra cuenta para unirla con esta.']);
        }

        return $this->protegida(fn (): Cuenta => DB::transaction(function () use ($actor, $principal, $version, $otra, $versionOtra): Cuenta {
            $restaurante = $this->bloquearRestaurante($actor);
            $principal = $this->vigente($restaurante, $principal, $version);
            $otra = $this->vigente($restaurante, $otra, $versionOtra);

            $propias = $principal->ocupacionesAbiertas()->count();
            $ajenas = $otra->ocupacionesAbiertas()->with('mesa')->get();

            if ($propias + $ajenas->count() > self::MESAS_MAXIMO) {
                throw ValidationException::withMessages(['otra_cuenta_id' => 'Juntas pasarían de '.self::MESAS_MAXIMO.' mesas. Quita alguna antes de unirlas.']);
            }

            $ahora = now();
            $comensalesAntes = $principal->comensales;

            foreach ($ajenas as $ocupacion) {
                $ocupacion->forceFill(['salida_at' => $ahora])->save();
                $this->ocupar($principal, $ocupacion->mesa, $ahora);
            }

            $rondasMovidas = $this->moverRondas($actor, $otra, $principal);

            $otra->forceFill([
                'estado' => 'fusionada',
                'fusionada_en_id' => $principal->id,
                'cerrada_at' => $ahora,
                'cerrada_por' => $actor->id,
                'version' => $otra->version + 1,
            ])->save();

            // Con rondas nuevas, una precuenta impresa ya no vale: la principal vuelve a abierta.
            $principal->forceFill([
                'comensales' => min(Cuenta::COMENSALES_MAXIMO, $principal->comensales + $otra->comensales),
                'version' => $principal->version + 1,
                ...($principal->estado === 'precuenta' && $rondasMovidas > 0 ? ['estado' => 'abierta'] : []),
            ])->save();

            CrearPedidoSalon::resolverAlertaPrecuenta($otra);

            if ($rondasMovidas > 0) {
                CrearPedidoSalon::resolverAlertaPrecuenta($principal);
            }

            $codigos = $ajenas->pluck('mesa.codigo')->all();
            $this->actividad->registrar($actor, 'mesas', 'fusiono_cuentas',
                "Unió la cuenta #{$otra->numero} con la #{$principal->numero}.", $principal,
                ['comensales' => $comensalesAntes], ['comensales' => $principal->comensales, 'cuenta_fusionada' => $otra->numero, 'mesas_recibidas' => $codigos, 'rondas_recibidas' => $rondasMovidas]);

            return $principal;
        }));
    }

    /**
     * Pasa la cuenta a otra persona activa del local que atienda el salón.
     */
    public function transferir(User $actor, Cuenta $cuenta, int $version, int $meseroId): Cuenta
    {
        return DB::transaction(function () use ($actor, $cuenta, $version, $meseroId): Cuenta {
            $restaurante = $this->bloquearRestaurante($actor);
            $cuenta = $this->vigente($restaurante, $cuenta, $version);

            $destino = User::where('restaurante_id', $restaurante->id)
                ->where('activo', true)
                ->whereIn('rol', self::ROLES_QUE_ATIENDEN)
                ->find($meseroId);

            if ($destino === null) {
                throw ValidationException::withMessages(['mesero_id' => 'Elige a una persona activa de tu local que atienda el salón.']);
            }

            if ($destino->id === $cuenta->mesero_id) {
                throw ValidationException::withMessages(['mesero_id' => "La cuenta ya es de {$destino->name}."]);
            }

            $anterior = $cuenta->mesero_id;
            $cuenta->forceFill(['mesero_id' => $destino->id, 'version' => $cuenta->version + 1])->save();

            $this->actividad->registrar($actor, 'mesas', 'transfirio_cuenta', "Transfirió la cuenta #{$cuenta->numero} a {$destino->name}.", $cuenta,
                ['mesero_id' => $anterior], ['mesero_id' => $destino->id]);

            return $cuenta;
        });
    }

    /**
     * "Cobrado en otro sistema": cierra la cuenta y libera todas sus mesas. El cierre por venta llega con la Caja.
     *
     * - $totalCentimos es el total que veía quien cierra; si no coincide con el del servidor, 409.
     * - Con rondas sin servir responde 409 con la lista (RondasSinServir), salvo que se confirme: entonces
     *   se marcan servidas. El total se compara igual al confirmar.
     * - Todos los pedidos no cancelados de la cuenta quedan pagados.
     *
     * @throws ConflictHttpException si la cuenta o su total cambiaron
     * @throws RondasSinServir
     */
    public function cerrarExterno(User $actor, Cuenta $cuenta, int $version, int $totalCentimos, bool $confirmar = false): Cuenta
    {
        return DB::transaction(function () use ($actor, $cuenta, $version, $totalCentimos, $confirmar): Cuenta {
            $cuenta = $this->vigente($this->bloquearRestaurante($actor), $cuenta, $version);

            if (self::totalCentimos($cuenta) !== $totalCentimos) {
                throw new ConflictHttpException(self::MENSAJE_TOTAL_CAMBIO);
            }

            $sinServir = $this->pedidosVivos($cuenta)->whereIn('estado', ['en_preparacion', 'listo'])->orderBy('ronda')->get();

            if ($sinServir->isNotEmpty() && ! $confirmar) {
                throw new RondasSinServir($sinServir->map(fn (Pedido $pedido): array => [
                    'id' => $pedido->id,
                    'numero' => $pedido->numero,
                    'ronda' => $pedido->ronda,
                    'estado' => $pedido->estado,
                ])->values()->all());
            }

            $ahora = now();

            foreach ($sinServir as $pedido) {
                $anterior = $pedido->estado;
                $pedido->forceFill(['estado' => 'entregado', 'entregado_at' => $ahora])->save();
                $this->historialPedido($pedido, $actor, $anterior, 'entregado', 'Servido al cerrar la cuenta.');
            }

            $this->pedidosVivos($cuenta)->update(['estado_pago' => 'pagado', 'pago_validado_por' => $actor->id, 'pago_validado_at' => $ahora]);
            $mesas = $this->liberar($cuenta, $ahora);

            $cuenta->forceFill([
                'estado' => 'cerrada',
                'cierre_tipo' => 'externo',
                'cerrada_at' => $ahora,
                'cerrada_por' => $actor->id,
                'version' => $cuenta->version + 1,
            ])->save();

            CrearPedidoSalon::resolverAlertaPrecuenta($cuenta);

            $this->actividad->registrar($actor, 'mesas', 'cerro_cuenta', "Cerró la cuenta #{$cuenta->numero} (cobrado en otro sistema).", $cuenta,
                ['estado' => 'abierta'], [
                    'estado' => 'cerrada',
                    'cierre_tipo' => 'externo',
                    'total' => number_format($totalCentimos / 100, 2, '.', ''),
                    'mesas_liberadas' => $mesas,
                    'rondas_servidas_al_cerrar' => $sinServir->pluck('ronda')->all(),
                ]);

            return $cuenta;
        });
    }

    /**
     * Anula la cuenta con un motivo y libera todas sus mesas. Sus pedidos no cancelados (también los
     * ya servidos) se cancelan con el mismo motivo, y cada uno lo deja en su historial.
     *
     * Con pedidos vivos solo admin o cajero; el mesero solo anula una cuenta suya sin pedidos
     * (CuentaPolicy lo revisa antes y aquí se vuelve a revisar con el local bloqueado).
     */
    public function anular(User $actor, Cuenta $cuenta, int $version, string $motivo): Cuenta
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 255) {
            throw ValidationException::withMessages(['motivo' => 'Escribe el motivo de la anulación (de 3 a 255 caracteres).']);
        }

        return DB::transaction(function () use ($actor, $cuenta, $version, $motivo): Cuenta {
            $cuenta = $this->vigente($this->bloquearRestaurante($actor), $cuenta, $version);
            $vivos = $this->pedidosVivos($cuenta)->orderBy('ronda')->get();

            if ($vivos->isNotEmpty() && ! in_array($actor->rol, ['admin', 'cajero'], true)) {
                throw new AuthorizationException('Esta cuenta ya tiene pedidos: solo el administrador o el cajero pueden anularla.');
            }

            $ahora = now();

            foreach ($vivos as $pedido) {
                $anterior = $pedido->estado;
                $pedido->forceFill([
                    'estado' => 'cancelado',
                    'cancelado_at' => $ahora,
                    'cancelacion_motivo' => 'otro',
                    'cancelacion_detalle' => "Cuenta anulada: {$motivo}",
                ])->save();
                $this->historialPedido($pedido, $actor, $anterior, 'cancelado', "Cuenta #{$cuenta->numero} anulada. Motivo: {$motivo}");
            }

            $mesas = $this->liberar($cuenta, $ahora);
            CrearPedidoSalon::resolverAlertaPrecuenta($cuenta);

            $cuenta->forceFill([
                'estado' => 'anulada',
                'anulada_at' => $ahora,
                'anulada_por' => $actor->id,
                'motivo_anulacion' => $motivo,
                'version' => $cuenta->version + 1,
            ])->save();

            $this->actividad->registrar($actor, 'mesas', 'anulo_cuenta', "Anuló la cuenta #{$cuenta->numero}. Motivo: {$motivo}", $cuenta,
                ['estado' => 'abierta'], ['estado' => 'anulada', 'motivo' => $motivo, 'mesas_liberadas' => $mesas, 'pedidos_cancelados' => $vivos->pluck('numero')->all()]);

            return $cuenta;
        });
    }

    /**
     * Cuentas abiertas a nombre de una persona (para avisar antes de que deje de atender).
     */
    public function abiertasDe(User $usuario): int
    {
        return Cuenta::withoutGlobalScopes()
            ->where('restaurante_id', $usuario->restaurante_id)
            ->where('mesero_id', $usuario->id)
            ->abiertas()
            ->count();
    }

    /**
     * Mesas que rompen el invariante: "ocupada" sin ocupación abierta, o con ocupación abierta sin estar "ocupada".
     * Debe estar siempre vacío; lo usan las pruebas y los scripts de concurrencia.
     *
     * @return list<int> ids de mesas
     */
    public static function mesasInconsistentes(int $restauranteId): array
    {
        $conOcupacion = OcupacionMesa::withoutGlobalScopes()
            ->where('restaurante_id', $restauranteId)
            ->whereNull('salida_at')
            ->pluck('mesa_id');

        return Mesa::withoutGlobalScopes()
            ->where('restaurante_id', $restauranteId)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('estado', 'ocupada')->whereNotIn('id', $conOcupacion))
                ->orWhere(fn ($q) => $q->where('estado', '!=', 'ocupada')->whereIn('id', $conOcupacion)))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * Total de la cuenta en céntimos: la suma de sus pedidos no cancelados (sus totales ya excluyen
     * las líneas anuladas). Siempre se calcula en el servidor.
     */
    public static function totalCentimos(Cuenta $cuenta): int
    {
        $total = Pedido::withoutGlobalScopes()
            ->where('cuenta_id', $cuenta->id)
            ->where('estado', '!=', 'cancelado')
            ->sum('total');

        return (int) round((float) $total * 100);
    }

    public static function exigirComensales(int $comensales): void
    {
        if ($comensales < 1 || $comensales > Cuenta::COMENSALES_MAXIMO) {
            throw ValidationException::withMessages(['comensales' => 'Los comensales van de 1 a '.Cuenta::COMENSALES_MAXIMO.'.']);
        }
    }

    /* ---------------------------------------------------------------------- */
    /* Internos */
    /* ---------------------------------------------------------------------- */

    /**
     * El bloqueo que ordena todas las operaciones del local. Es protected solo para que el control
     * negativo de tests/Concurrencia pueda ejecutar este mismo código sin él.
     */
    protected function bloquearRestaurante(User $actor): Restaurante
    {
        return Restaurante::lockForUpdate()->findOrFail($actor->restaurante_id);
    }

    /**
     * La cuenta tal como está ahora, si sigue abierta y nadie la cambió desde la version que se vio.
     */
    private function vigente(Restaurante $restaurante, Cuenta $cuenta, int $version): Cuenta
    {
        $actual = Cuenta::withoutGlobalScopes()->where('restaurante_id', $restaurante->id)->findOrFail($cuenta->id);

        if ($actual->version !== $version) {
            throw new ConflictHttpException(self::MENSAJE_DESACTUALIZADA);
        }

        if (! $actual->estaAbierta()) {
            throw new ConflictHttpException(self::MENSAJE_YA_NO_ABIERTA);
        }

        return $actual;
    }

    /**
     * Las mesas pedidas, en ese orden, si todas son del local y se pueden ocupar ahora.
     *
     * @param  list<int>  $mesaIds
     * @return EloquentCollection<int, Mesa>
     */
    private function mesasDisponibles(Restaurante $restaurante, array $mesaIds): EloquentCollection
    {
        $mesas = Mesa::withoutGlobalScopes()
            ->where('restaurante_id', $restaurante->id)
            ->whereIn('id', $mesaIds)
            ->with(['area' => fn ($query) => $query->withoutGlobalScopes()])
            ->get()
            ->sortBy(fn (Mesa $mesa): int => (int) array_search($mesa->id, $mesaIds, true))
            ->values();

        if ($mesas->count() !== count($mesaIds)) {
            throw ValidationException::withMessages(['mesas' => 'Elige mesas de tu local.']);
        }

        foreach ($mesas as $mesa) {
            if (! $mesa->activa || ! $mesa->area?->activa || $mesa->estado !== 'disponible') {
                throw new ConflictHttpException("La mesa {$mesa->codigo} ya no está disponible. Actualizamos el plano.");
            }
        }

        return $mesas;
    }

    /**
     * @param  EloquentCollection<int, OcupacionMesa>  $ocupaciones  las abiertas de la cuenta
     */
    private function ocupacionDe(EloquentCollection $ocupaciones, int $mesaId): OcupacionMesa
    {
        return $ocupaciones->firstWhere('mesa_id', $mesaId)
            ?? throw new ConflictHttpException('Esa mesa ya no está en la cuenta. Actualizamos el plano.');
    }

    private function ocupar(Cuenta $cuenta, Mesa $mesa, Carbon $momento): void
    {
        $ocupacion = new OcupacionMesa(['mesa_id' => $mesa->id, 'cuenta_id' => $cuenta->id, 'ingreso_at' => $momento]);
        $ocupacion->forceFill(['restaurante_id' => $cuenta->restaurante_id])->save();

        $mesa->forceFill(['estado' => 'ocupada'])->save();
    }

    private function desocupar(OcupacionMesa $ocupacion, Carbon $momento): void
    {
        $ocupacion->forceFill(['salida_at' => $momento])->save();

        Mesa::withoutGlobalScopes()->whereKey($ocupacion->mesa_id)->where('estado', 'ocupada')->update(['estado' => 'disponible']);
    }

    /**
     * Cierra todas las ocupaciones abiertas de la cuenta y deja sus mesas disponibles.
     *
     * @return list<string> códigos de las mesas liberadas
     */
    private function liberar(Cuenta $cuenta, Carbon $momento): array
    {
        $ocupaciones = $cuenta->ocupacionesAbiertas()->with('mesa')->get();

        foreach ($ocupaciones as $ocupacion) {
            $this->desocupar($ocupacion, $momento);
        }

        return $ocupaciones->pluck('mesa.codigo')->all();
    }

    /**
     * @return Builder<Pedido>
     */
    private function pedidosVivos(Cuenta $cuenta): Builder
    {
        return Pedido::withoutGlobalScopes()->where('cuenta_id', $cuenta->id)->where('estado', '!=', 'cancelado');
    }

    /**
     * Pasa los pedidos de $desde al final de $hacia. Devuelve cuántos movió.
     */
    private function moverRondas(User $actor, Cuenta $desde, Cuenta $hacia): int
    {
        $pedidos = Pedido::withoutGlobalScopes()->where('cuenta_id', $desde->id)->orderBy('ronda')->orderBy('id')->get();
        $base = (int) Pedido::withoutGlobalScopes()->where('cuenta_id', $hacia->id)->max('ronda');

        foreach ($pedidos->values() as $indice => $pedido) {
            $anterior = $pedido->ronda;
            $pedido->forceFill(['cuenta_id' => $hacia->id, 'ronda' => $base + $indice + 1])->save();
            $this->historialPedido($pedido, $actor, $pedido->estado, $pedido->estado,
                "Pasó a la cuenta #{$hacia->numero} como ronda {$pedido->ronda}. Era la ronda {$anterior} de la cuenta #{$desde->numero}.");
        }

        return $pedidos->count();
    }

    private function historialPedido(Pedido $pedido, User $actor, string $anterior, string $nuevo, string $nota): void
    {
        PedidoHistorial::create([
            'pedido_id' => $pedido->id,
            'estado_anterior' => $anterior,
            'estado_nuevo' => $nuevo,
            'user_id' => $actor->id,
            'nota' => mb_substr($nota, 0, 255),
        ]);
    }

    private function subirVersion(Cuenta $cuenta): void
    {
        $cuenta->forceFill(['version' => $cuenta->version + 1])->save();
    }

    /**
     * @param  list<string>  $codigos
     */
    private function etiquetaMesas(array $codigos): string
    {
        return count($codigos) === 1 ? "la mesa {$codigos[0]}" : 'las mesas '.implode(' + ', $codigos);
    }

    /**
     * Con el restaurante bloqueado los índices únicos no deberían saltar nunca. Si saltan
     * (otra vía escribió a la vez), se responde 409 para que la persona reintente, no 500.
     *
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     */
    private function protegida(callable $operacion): mixed
    {
        try {
            return $operacion();
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException(self::MENSAJE_INDICE);
        }
    }
}
