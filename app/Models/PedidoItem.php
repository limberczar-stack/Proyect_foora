<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoItem extends Model
{
    protected $table = 'pedido_items';

    protected $fillable = [
        'pedido_id',
        'producto_id',
        'variante_id',
        'variante_nombre',
        'nombre',
        'cantidad',
        'precio_unitario',
        'subtotal',
        'estado',
        'observacion',
        'tiempo_estimado_min',
        'listo_at',
    ];

    /**
     * anulado_at, anulado_por y motivo_anulacion los fija GestionarPedidoSalon con forceFill:
     * una línea enviada a cocina no se borra, se anula con una marca.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'precio_unitario' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tiempo_estimado_min' => 'integer',
            'listo_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }

    public function estaAnulada(): bool
    {
        return $this->anulado_at !== null;
    }

    /**
     * Líneas que cuentan para el total: las no anuladas.
     *
     * @param  Builder<PedidoItem>  $query
     */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('anulado_at'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    /**
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ProductoVariante, $this>
     */
    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    /**
     * @return HasMany<PedidoItemOpcion, $this>
     */
    public function opciones(): HasMany
    {
        return $this->hasMany(PedidoItemOpcion::class);
    }
}
