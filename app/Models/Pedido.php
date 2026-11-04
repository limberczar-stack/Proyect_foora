<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Pedido extends Model
{
    use BelongsToRestaurante;

    protected $table = 'pedidos';

    protected $fillable = [
        'restaurante_id',
        'numero',
        'user_id',
        'nombre_cliente',
        'telefono_cliente',
        'tipo',
        'origen',
        'mesa_id',
        'direccion_entrega',
        'referencia_entrega',
        'latitud',
        'longitud',
        'repartidor_id',
        'metodo_pago_id',
        'estado_pago',
        'referencia_pago',
        'pago_validado_por',
        'pago_validado_at',
        'subtotal',
        'descuento',
        'costo_delivery',
        'total',
        'promocion_id',
        'observaciones',
        'estado',
        'tiempo_estimado_min',
        'aceptado_at',
        'listo_at',
        'salida_at',
        'eta_at',
        'recoger_antes_de',
        'entregado_at',
        'cancelado_at',
        'cancelacion_motivo',
        'cancelacion_detalle',
        'registrado_como_perdida',
        'creado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'latitud' => 'decimal:7',
            'longitud' => 'decimal:7',
            'pago_validado_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'costo_delivery' => 'decimal:2',
            'total' => 'decimal:2',
            'tiempo_estimado_min' => 'integer',
            'aceptado_at' => 'datetime',
            'listo_at' => 'datetime',
            'salida_at' => 'datetime',
            'eta_at' => 'datetime',
            'recoger_antes_de' => 'datetime',
            'entregado_at' => 'datetime',
            'cancelado_at' => 'datetime',
            'registrado_como_perdida' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Mesa, $this>
     */
    public function mesa(): BelongsTo
    {
        return $this->belongsTo(Mesa::class);
    }

    /**
     * @return BelongsTo<Repartidor, $this>
     */
    public function repartidor(): BelongsTo
    {
        return $this->belongsTo(Repartidor::class);
    }

    /**
     * @return BelongsTo<MetodoPago, $this>
     */
    public function metodoPago(): BelongsTo
    {
        return $this->belongsTo(MetodoPago::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pago_validado_por');
    }

    /**
     * @return BelongsTo<Promocion, $this>
     */
    public function promocion(): BelongsTo
    {
        return $this->belongsTo(Promocion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * @return HasMany<PedidoItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }

    /**
     * @return HasMany<PedidoHistorial, $this>
     */
    public function historial(): HasMany
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
     * @return HasMany<OcupacionMesa, $this>
     */
    public function ocupacionesMesa(): HasMany
    {
        return $this->hasMany(OcupacionMesa::class);
    }

    /**
     * @return HasOne<Resena, $this>
     */
    public function resena(): HasOne
    {
        return $this->hasOne(Resena::class);
    }
}
