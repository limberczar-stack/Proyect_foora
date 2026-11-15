<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Database\Factories\MesaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Mesa del local. El código es único entre las activas (índice uq_mesa_activa sobre la columna
 * generada codigo_activo, que calcula la base de datos y nunca se escribe).
 * Las reglas de gestión viven en GestionarMesas.
 */
class Mesa extends Model
{
    /** @use HasFactory<MesaFactory> */
    use BelongsToRestaurante, HasFactory;

    public const CAPACIDAD_MINIMA = 1;

    public const CAPACIDAD_MAXIMA = 30;

    public const CODIGO_MAXIMO = 20;

    /**
     * Estados que alguien puede fijar a mano. Ocupada y reservada saldrán de cuentas y reservas.
     *
     * @var list<string>
     */
    public const ESTADOS_MANUALES = ['disponible', 'fuera_servicio'];

    /**
     * Todos los estados que puede mostrar el mapa de mesas.
     *
     * @var list<string>
     */
    public const ESTADOS = ['disponible', 'ocupada', 'reservada', 'fuera_servicio'];

    protected $table = 'mesas';

    protected $fillable = [
        'restaurante_id',
        'area_id',
        'codigo',
        'capacidad',
        'orden',
        'estado',
        'pos_x',
        'pos_y',
        'activa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacidad' => 'integer',
            'orden' => 'integer',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'activa' => 'boolean',
        ];
    }

    public function scopeOrdenadas(Builder $query): void
    {
        $query->orderBy('orden')->orderBy('id');
    }

    public function scopeActivas(Builder $query): void
    {
        $query->where($query->qualifyColumn('activa'), true);
    }

    public function estaFueraDeServicio(): bool
    {
        return $this->estado === 'fuera_servicio';
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
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
    public function ocupaciones(): HasMany
    {
        return $this->hasMany(OcupacionMesa::class);
    }

    /**
     * La ocupación en curso, si la mesa está en una cuenta.
     *
     * @return HasOne<OcupacionMesa, $this>
     */
    public function ocupacionAbierta(): HasOne
    {
        return $this->hasOne(OcupacionMesa::class)->whereNull('salida_at');
    }
}
