<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Roles del personal de un restaurante (entran al panel con restaurante asignado).
     *
     * @var list<string>
     */
    public const ROLES_PERSONAL = ['admin', 'cajero', 'mesero', 'cocina', 'repartidor'];

    /**
     * Nombre visible de cada rol.
     *
     * @var array<string, string>
     */
    public const ROL_ETIQUETAS = [
        'superadmin' => 'Superadmin FOORA',
        'admin' => 'Administrador',
        'cajero' => 'Cajero',
        'mesero' => 'Mesero',
        'cocina' => 'Cocina',
        'repartidor' => 'Repartidor',
        'cliente' => 'Cliente',
    ];

    protected $fillable = [
        'restaurante_id',
        'name',
        'email',
        'telefono',
        'password',
        'rol',
        'activo',
        'ultimo_acceso_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'ultimo_acceso_at' => 'datetime',
        ];
    }

    public function esSuperadmin(): bool
    {
        return $this->rol === 'superadmin';
    }

    public function esPersonal(): bool
    {
        return in_array($this->rol, self::ROLES_PERSONAL, true);
    }

    /**
     * Personal activo con restaurante activo, o superadmin activo.
     */
    public function puedeEntrarAlPanel(): bool
    {
        if (! $this->activo) {
            return false;
        }

        if ($this->esSuperadmin()) {
            return true;
        }

        return $this->esPersonal()
            && $this->restaurante_id !== null
            && $this->restaurante?->activo === true;
    }

    public function rolEtiqueta(): string
    {
        return self::ROL_ETIQUETAS[$this->rol] ?? ucfirst((string) $this->rol);
    }

    public function iniciales(): string
    {
        return Str::of($this->name)->explode(' ')->filter()->take(2)
            ->map(fn (string $parte): string => Str::upper(Str::substr($parte, 0, 1)))
            ->implode('');
    }

    /**
     * @return BelongsTo<Restaurante, $this>
     */
    public function restaurante(): BelongsTo
    {
        return $this->belongsTo(Restaurante::class);
    }

    /**
     * @return HasOne<Repartidor, $this>
     */
    public function repartidor(): HasOne
    {
        return $this->hasOne(Repartidor::class);
    }

    /**
     * Pedidos hechos como cliente.
     *
     * @return HasMany<Pedido, $this>
     */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    /**
     * Pedidos digitados por este usuario del local.
     *
     * @return HasMany<Pedido, $this>
     */
    public function pedidosCreados(): HasMany
    {
        return $this->hasMany(Pedido::class, 'creado_por');
    }

    /**
     * @return HasMany<Pedido, $this>
     */
    public function pagosValidados(): HasMany
    {
        return $this->hasMany(Pedido::class, 'pago_validado_por');
    }

    /**
     * @return HasMany<PedidoHistorial, $this>
     */
    public function pedidoHistorial(): HasMany
    {
        return $this->hasMany(PedidoHistorial::class);
    }

    /**
     * @return HasMany<PromocionUso, $this>
     */
    public function promocionUsos(): HasMany
    {
        return $this->hasMany(PromocionUso::class);
    }

    /**
     * @return HasMany<Reserva, $this>
     */
    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class);
    }

    /**
     * @return HasMany<ReservaAplazamiento, $this>
     */
    public function aplazamientosResueltos(): HasMany
    {
        return $this->hasMany(ReservaAplazamiento::class, 'resuelta_por');
    }

    /**
     * Ocupaciones de mesa atendidas como mesero.
     *
     * @return HasMany<OcupacionMesa, $this>
     */
    public function ocupacionesAtendidas(): HasMany
    {
        return $this->hasMany(OcupacionMesa::class, 'mesero_id');
    }

    /**
     * @return HasMany<ActividadLog, $this>
     */
    public function actividadLogs(): HasMany
    {
        return $this->hasMany(ActividadLog::class);
    }

    /**
     * @return HasMany<DireccionCliente, $this>
     */
    public function direcciones(): HasMany
    {
        return $this->hasMany(DireccionCliente::class);
    }

    /**
     * @return HasMany<Resena, $this>
     */
    public function resenas(): HasMany
    {
        return $this->hasMany(Resena::class);
    }

    /**
     * @return BelongsToMany<Restaurante, $this>
     */
    public function favoritos(): BelongsToMany
    {
        return $this->belongsToMany(Restaurante::class, 'favoritos', 'user_id', 'restaurante_id')
            ->withTimestamps(updatedAt: false);
    }
}
