<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\RestauranteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Restaurante extends Model
{
    /** @use HasFactory<RestauranteFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'restaurantes';

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
