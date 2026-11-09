<?php

namespace App\Models;

use App\Support\WhatsApp;
use Carbon\CarbonInterface;
use Database\Factories\RestauranteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Restaurante extends Model
{
    /** @use HasFactory<RestauranteFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Avisos del panel activos cuando el local todavía no eligió.
     *
     * @var array{nuevos_pedidos: bool, reservas: bool, alertas: bool}
     */
    public const NOTIFICACIONES_POR_DEFECTO = [
        'nuevos_pedidos' => true,
        'reservas' => true,
        'alertas' => true,
    ];

    public const ZONA_POR_DEFECTO = 'America/Lima';

    protected $table = 'restaurantes';

    /**
     * notas_internas son del equipo de FOORA: nunca salen a JSON ni llegan al local.
     *
     * @var list<string>
     */
    protected $hidden = ['notas_internas'];

    protected $fillable = [
        'rubro_id',
        'plan_id',
        'nombre',
        'slug',
        'descripcion',
        'logo',
        'telefono',
        'email',
        'direccion',
        'ciudad',
        'latitud',
        'longitud',
        'estado_local',
        'visible_en_app',
        'acepta_pedidos_auto',
        'sonido_nuevos_pedidos',
        'promociones_habilitadas',
        'acepta_delivery',
        'acepta_recojo',
        'acepta_reservas',
        'costo_delivery',
        'pedido_minimo',
        'tiempo_prep_min',
        'tolerancia_reserva_min',
        'umbral_ocupacion_alta',
        'moneda',
        'zona_horaria',
        'config_notificaciones',
        'calificacion_promedio',
        'total_resenas',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitud' => 'decimal:7',
            'longitud' => 'decimal:7',
            'visible_en_app' => 'boolean',
            'acepta_pedidos_auto' => 'boolean',
            'sonido_nuevos_pedidos' => 'boolean',
            'promociones_habilitadas' => 'boolean',
            'acepta_delivery' => 'boolean',
            'acepta_recojo' => 'boolean',
            'acepta_reservas' => 'boolean',
            'costo_delivery' => 'decimal:2',
            'pedido_minimo' => 'decimal:2',
            'tiempo_prep_min' => 'integer',
            'tolerancia_reserva_min' => 'integer',
            'umbral_ocupacion_alta' => 'integer',
            'config_notificaciones' => 'array',
            'calificacion_promedio' => 'decimal:1',
            'total_resenas' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function estaAbierto(): bool
    {
        return $this->estado_local === 'abierto';
    }

    public function estaSuspendido(): bool
    {
        return ! $this->activo;
    }

    /**
     * ÚNICA regla de si el local opera: no está suspendido, su suscripción no está cancelada y no
     * pasó el periodo de gracia. Sale de las fechas, así que no depende del comando diario.
     * Un local sin ninguna suscripción opera (el alta siempre crea una).
     *
     * No confundir con abiertoAhora(): esa dice si atiende en este momento.
     */
    public function operativo(): bool
    {
        if ($this->estaSuspendido()) {
            return false;
        }

        $suscripcion = $this->suscripcionActual;

        if ($suscripcion === null) {
            return true;
        }

        return $suscripcion->estado !== 'cancelada'
            && $this->diasParaVencer() >= -Suscripcion::DIAS_GRACIA;
    }

    /**
     * La fecha de hoy (Y-m-d) en la zona del local: el vencimiento no depende de la zona del servidor.
     */
    public function fechaHoy(): string
    {
        return Carbon::now($this->zona_horaria ?: self::ZONA_POR_DEFECTO)->toDateString();
    }

    /**
     * Días hasta vence_el: 0 si vence hoy, negativo si ya venció, null sin suscripción.
     */
    public function diasParaVencer(): ?int
    {
        $suscripcion = $this->suscripcionActual;

        if ($suscripcion === null) {
            return null;
        }

        return (int) Carbon::parse($this->fechaHoy(), 'UTC')
            ->diffInDays(Carbon::parse($suscripcion->vence_el->toDateString(), 'UTC'), false);
    }

    /**
     * Venció, pero todavía opera.
     */
    public function enGracia(): bool
    {
        return $this->operativo() && $this->suscripcionActual !== null && $this->diasParaVencer() < 0;
    }

    /**
     * Días de gracia que quedan después de hoy (0 = hoy es el último).
     */
    public function diasDeGracia(): int
    {
        return $this->enGracia() ? Suscripcion::DIAS_GRACIA + $this->diasParaVencer() : 0;
    }

    /**
     * Estado de la suscripción según las fechas de hoy, o null si no tiene.
     */
    public function estadoSuscripcion(): ?string
    {
        return $this->suscripcionActual?->estadoEl($this->fechaHoy());
    }

    /**
     * Aviso para el panel del local, o null si no hay nada que avisar.
     *
     * @return array{tono: 'danger'|'warning', titulo: string, mensaje: string}|null
     */
    public function avisoSuscripcion(): ?array
    {
        if ($this->estaSuspendido()) {
            return [
                'tono' => 'danger',
                'titulo' => 'Tu local está suspendido',
                'mensaje' => 'Tu vitrina no está disponible para tus clientes. Puedes terminar los pedidos en curso. Contacta a FOORA para reactivarlo.',
            ];
        }

        if ($this->suscripcionActual === null) {
            return null;
        }

        if (! $this->operativo()) {
            return [
                'tono' => 'danger',
                'titulo' => $this->suscripcionActual->estado === 'cancelada' ? 'Tu suscripción está cancelada' : 'Tu suscripción venció',
                'mensaje' => 'Tu vitrina no está disponible para tus clientes. Puedes terminar los pedidos en curso. Contacta a FOORA para reactivarla.',
            ];
        }

        $dias = $this->diasParaVencer();

        if ($dias < 0) {
            $resto = $this->diasDeGracia();

            return [
                'tono' => 'danger',
                'titulo' => 'Tu suscripción venció',
                'mensaje' => match ($resto) {
                    0 => 'Hoy es el último día de gracia. Desde mañana tu vitrina dejará de estar disponible. Contacta a FOORA para renovarla.',
                    1 => 'Te queda 1 día de gracia. Después tu vitrina dejará de estar disponible. Contacta a FOORA para renovarla.',
                    default => "Te quedan {$resto} días de gracia. Después tu vitrina dejará de estar disponible. Contacta a FOORA para renovarla.",
                },
            ];
        }

        if ($dias > Suscripcion::DIAS_AVISO) {
            return null;
        }

        $cuando = match ($dias) {
            0 => 'vence hoy',
            1 => 'vence mañana',
            default => "vence en {$dias} días",
        };

        return [
            'tono' => 'warning',
            'titulo' => $this->suscripcionActual->estado === 'prueba' ? "Tu prueba gratis {$cuando}" : "Tu suscripción {$cuando}",
            'mensaje' => 'Contacta a FOORA para renovarla y que tu vitrina siga disponible.',
        ];
    }

    /**
     * Preferencias de avisos del panel, con los valores por defecto para las claves que falten.
     *
     * @return array{nuevos_pedidos: bool, reservas: bool, alertas: bool}
     */
    public function notificaciones(): array
    {
        $guardadas = is_array($this->config_notificaciones) ? $this->config_notificaciones : [];
        $resultado = [];

        foreach (self::NOTIFICACIONES_POR_DEFECTO as $clave => $porDefecto) {
            $resultado[$clave] = array_key_exists($clave, $guardadas) ? (bool) $guardadas[$clave] : $porDefecto;
        }

        return $resultado;
    }

    /**
     * URL de la vitrina pública del local.
     */
    public function urlVitrina(): string
    {
        return route('vitrina.show', $this->slug);
    }

    /**
     * Abierto de verdad en este momento: el local no se cerró a mano y está dentro de su horario,
     * en su zona horaria. Sin horarios registrados manda solo estado_local.
     *
     * Quien lo llame sin sesión o desde otro restaurante debe cargar antes horariosAtencion sin scope.
     */
    public function abiertoAhora(?CarbonInterface $momento = null): bool
    {
        if (! $this->estaAbierto()) {
            return false;
        }

        if ($this->horariosAtencion->isEmpty()) {
            return true;
        }

        $ahora = ($momento ?? now())->copy()->setTimezone($this->zona_horaria);
        $hora = $ahora->format('H:i');
        $hoy = $this->horariosAtencion->firstWhere('dia_semana', $ahora->dayOfWeekIso);
        $ayer = $this->horariosAtencion->firstWhere('dia_semana', $ahora->dayOfWeekIso === 1 ? 7 : $ahora->dayOfWeekIso - 1);

        return (bool) ($hoy?->cubre($hora) || $ayer?->cubreMadrugadaSiguiente($hora));
    }

    /**
     * El horario de hoy en palabras, o null si no tiene horarios registrados.
     */
    public function horarioDeHoyTexto(?CarbonInterface $momento = null): ?string
    {
        if ($this->horariosAtencion->isEmpty()) {
            return null;
        }

        $dia = ($momento ?? now())->copy()->setTimezone($this->zona_horaria)->dayOfWeekIso;
        $hoy = $this->horariosAtencion->firstWhere('dia_semana', $dia);

        return $hoy?->atiende() ? 'Hoy atiende de '.$hoy->rangoTexto() : 'Hoy no atiende';
    }

    /**
     * Métodos activos que el cliente puede usar. Yape o Plin sin número no se ofrecen:
     * no tendría a dónde pagar. Requiere metodosPago cargado.
     *
     * @return Collection<int, MetodoPago>
     */
    public function metodosPagoDisponibles(): Collection
    {
        return $this->metodosPago
            ->filter(fn (MetodoPago $metodo): bool => (bool) $metodo->pivot->activo && (! $metodo->requiere_validacion || filled($metodo->pivot->numero_cuenta)))
            ->values()
            ->toBase();
    }

    /**
     * Enlace de WhatsApp al local con el mensaje listo, o null si no tiene un celular válido.
     */
    public function whatsappUrl(string $mensaje): ?string
    {
        return WhatsApp::enlace($this->telefono, $mensaje);
    }

    public function iniciales(): string
    {
        $palabras = collect(explode(' ', $this->nombre))
            ->reject(fn (string $palabra): bool => mb_strlen($palabra) <= 2 || in_array(mb_strtolower($palabra), ['pollería', 'cevichería', 'cafetería', 'chifa'], true))
            ->values();

        $base = $palabras->isNotEmpty() ? $palabras : collect([$this->nombre]);

        return $base->take(2)
            ->map(fn (string $palabra): string => mb_strtoupper(mb_substr($palabra, 0, 1)))
            ->implode('');
    }

    /**
     * URL pública del logo, o null si no tiene.
     */
    public function logoUrl(): ?string
    {
        if (! $this->logo) {
            return null;
        }

        return str_starts_with($this->logo, 'http') ? $this->logo : asset('storage/'.$this->logo);
    }

    /**
     * @return BelongsTo<Rubro, $this>
     */
    public function rubro(): BelongsTo
    {
        return $this->belongsTo(Rubro::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Suscripcion, $this>
     */
    public function suscripciones(): HasMany
    {
        return $this->hasMany(Suscripcion::class);
    }

    /**
     * La suscripción vigente: la más reciente. Sin scope de tenant porque la consultan la vitrina
     * pública, el superadmin y el comando diario.
     *
     * @return HasOne<Suscripcion, $this>
     */
    public function suscripcionActual(): HasOne
    {
        return $this->hasOne(Suscripcion::class)
            ->withoutGlobalScope(Suscripcion::RESTAURANTE_SCOPE)
            ->latestOfMany('id');
    }

    /**
     * @return HasMany<HorarioAtencion, $this>
     */
    public function horariosAtencion(): HasMany
    {
        return $this->hasMany(HorarioAtencion::class);
    }

    /**
     * @return HasMany<RestauranteMetodoPago, $this>
     */
    public function restauranteMetodosPago(): HasMany
    {
        return $this->hasMany(RestauranteMetodoPago::class);
    }

    /**
     * @return BelongsToMany<MetodoPago, $this>
     */
    public function metodosPago(): BelongsToMany
    {
        return $this->belongsToMany(MetodoPago::class, 'restaurante_metodos_pago', 'restaurante_id', 'metodo_pago_id')
            ->withPivot(['id', 'activo', 'numero_cuenta', 'titular'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Impresora, $this>
     */
    public function impresoras(): HasMany
    {
        return $this->hasMany(Impresora::class);
    }

    /**
     * @return HasMany<CategoriaProducto, $this>
     */
    public function categoriasProducto(): HasMany
    {
        return $this->hasMany(CategoriaProducto::class);
    }

    /**
     * @return HasMany<Producto, $this>
     */
    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }

    /**
     * @return HasMany<Area, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    /**
     * @return HasMany<Mesa, $this>
     */
    public function mesas(): HasMany
    {
        return $this->hasMany(Mesa::class);
    }

    /**
     * @return HasMany<Repartidor, $this>
     */
    public function repartidores(): HasMany
    {
        return $this->hasMany(Repartidor::class);
    }

    /**
     * @return HasMany<Promocion, $this>
     */
    public function promociones(): HasMany
    {
        return $this->hasMany(Promocion::class);
    }

    /**
     * @return HasMany<Pedido, $this>
     */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    /**
     * @return HasMany<Reserva, $this>
     */
    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class);
    }

    /**
     * @return HasMany<OcupacionMesa, $this>
     */
    public function ocupacionesMesa(): HasMany
    {
        return $this->hasMany(OcupacionMesa::class);
    }

    /**
     * @return HasMany<Alerta, $this>
     */
    public function alertas(): HasMany
    {
        return $this->hasMany(Alerta::class);
    }

    /**
     * @return HasMany<ActividadLog, $this>
     */
    public function actividadLogs(): HasMany
    {
        return $this->hasMany(ActividadLog::class);
    }

    /**
     * @return HasMany<Resena, $this>
     */
    public function resenas(): HasMany
    {
        return $this->hasMany(Resena::class);
    }

    /**
     * Clientes que marcaron este restaurante como favorito.
     *
     * @return BelongsToMany<User, $this>
     */
    public function favoritoDe(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favoritos', 'restaurante_id', 'user_id')
            ->withTimestamps(updatedAt: false);
    }
}
