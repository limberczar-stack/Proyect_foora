<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use App\Support\WhatsApp;
use Database\Factories\PedidoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Pedido extends Model
{
    /** @use HasFactory<PedidoFactory> */
    use BelongsToRestaurante, HasFactory;

    public const LONGITUD_CODIGO = 32;

    public const MAX_ACTIVOS_POR_TELEFONO = 3;

    /** Un pedido de recojo listo espera este tiempo antes de considerarse no recogido. */
    public const MINUTOS_PARA_RECOGER = 30;

    public const MINUTOS_ETA_POR_DEFECTO = 20;

    public const MINUTOS_ETA_MAXIMO = 180;

    /**
     * @var array<string, string>
     */
    public const ESTADOS = [
        'nuevo' => 'Nuevo',
        'en_preparacion' => 'En preparación',
        'listo' => 'Listo',
        'en_camino' => 'En camino',
        'entregado' => 'Entregado',
        'cancelado' => 'Cancelado',
    ];

    /**
     * Estado actual => estados a los que puede pasar. en_camino es solo para delivery, y un
     * recojo listo pasa directo a entregado (puedePasarA aplica esas dos condiciones).
     *
     * @var array<string, list<string>>
     */
    public const TRANSICIONES = [
        'nuevo' => ['en_preparacion', 'cancelado'],
        'en_preparacion' => ['listo', 'cancelado'],
        'listo' => ['en_camino', 'entregado', 'cancelado'],
        'en_camino' => ['entregado', 'cancelado'],
    ];

    /**
     * Motivos de cancelación tal como los ve el personal.
     *
     * @var array<string, string>
     */
    public const MOTIVOS_CANCELACION = [
        'cliente_no_respondio' => 'El cliente no respondió',
        'cliente_cancelo' => 'El cliente canceló',
        'pago_no_confirmado' => 'Pago no confirmado',
        'cliente_no_llego' => 'El cliente no recogió',
        'tiempo_espera_excedido' => 'Tiempo de espera excedido',
        'problema_delivery' => 'Problema con el delivery',
        'restaurante_rechazo' => 'El local no puede atenderlo',
        'otro' => 'Otro motivo',
    ];

    /** Versión vigente del aviso de privacidad que acepta el cliente. */
    public const CONSENTIMIENTO_VERSION = '2026-11-v1';

    protected $table = 'pedidos';

    protected $fillable = [
        'restaurante_id',
        'numero',
        'codigo_seguimiento',
        'idempotency_key',
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
        'consentimiento_at',
        'consentimiento_version',
    ];

    /**
     * El código da acceso al seguimiento: nunca debe salir en listados ni serializaciones.
     *
     * @var list<string>
     */
    protected $hidden = ['codigo_seguimiento', 'idempotency_key'];

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
            'consentimiento_at' => 'datetime',
        ];
    }

    /**
     * Pedidos que el local todavía debe atender.
     *
     * @param  Builder<Pedido>  $query
     */
    public function scopeActivos(Builder $query): void
    {
        $query->whereNotIn('estado', ['entregado', 'cancelado']);
    }

    public function primerNombre(): string
    {
        return strtok(trim($this->nombre_cliente), " \t") ?: '';
    }

    public function tipoTexto(): string
    {
        return $this->tipo === 'delivery' ? 'Delivery a domicilio' : 'Recojo en local';
    }

    public function puedePasarA(string $destino): bool
    {
        if (! in_array($destino, self::TRANSICIONES[$this->estado] ?? [], true)) {
            return false;
        }

        return match (true) {
            $destino === 'en_camino' => $this->tipo === 'delivery',
            $destino === 'entregado' && $this->estado === 'listo' => $this->tipo !== 'delivery',
            default => true,
        };
    }

    /**
     * @return list<string>
     */
    public function destinosPermitidos(): array
    {
        return array_values(array_filter(self::TRANSICIONES[$this->estado] ?? [], $this->puedePasarA(...)));
    }

    public function llamadaCliente(): ?string
    {
        return WhatsApp::llamada($this->telefono_cliente);
    }

    public function whatsappCliente(?string $mensaje = null): ?string
    {
        return WhatsApp::enlace($this->telefono_cliente, $mensaje);
    }

    /**
     * Mensaje para avisar al cliente según el estado, con el enlace a su seguimiento.
     */
    public function mensajeParaCliente(Restaurante $restaurante): string
    {
        $nombre = $this->primerNombre();
        $numero = "#{$this->numero}";

        $texto = match ($this->estado) {
            'en_preparacion' => "Hola {$nombre}, ya estamos preparando tu pedido {$numero} en {$restaurante->nombre}.",
            'listo' => $this->tipo === 'delivery'
                ? "Hola {$nombre}, tu pedido {$numero} está listo y saldrá en breve."
                : "Hola {$nombre}, tu pedido {$numero} está listo. Ya puedes recogerlo en {$restaurante->nombre}.",
            'en_camino' => "Hola {$nombre}, tu pedido {$numero} va en camino".($this->eta_at ? ' y llega cerca de las '.$this->eta_at->timezone($restaurante->zona_horaria)->format('H:i') : '').'.',
            'entregado' => "Hola {$nombre}, gracias por tu pedido {$numero} en {$restaurante->nombre}.",
            'cancelado' => "Hola {$nombre}, lamentamos avisarte que tu pedido {$numero} fue cancelado.",
            default => "Hola {$nombre}, recibimos tu pedido {$numero} en {$restaurante->nombre}. Te avisaremos cuando lo aceptemos.",
        };

        return $texto.' Sigue tu pedido aquí: '.route('vitrina.pedido.seguimiento', [$restaurante->slug, $this->codigo_seguimiento]);
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
