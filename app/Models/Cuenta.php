<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Database\Factories\CuentaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuenta de salón: una atención abierta en una o varias mesas. Sus mesas actuales son las
 * ocupaciones sin salida_at. Todo cambio pasa por GestionarCuentas, que sube version;
 * nunca se borra: se cierra, se anula o queda fusionada en otra.
 */
class Cuenta extends Model
{
    /** @use HasFactory<CuentaFactory> */
    use BelongsToRestaurante, HasFactory;

    public const COMENSALES_MAXIMO = 99;

    /**
     * Estados en los que la cuenta sigue ocupando mesas. precuenta llega en una fase siguiente.
     *
     * @var list<string>
     */
    public const ESTADOS_ABIERTOS = ['abierta', 'precuenta'];

    /**
     * @var array<string, string>
     */
    public const ESTADO_ETIQUETAS = [
        'abierta' => 'Abierta',
        'precuenta' => 'Precuenta pedida',
        'cerrada' => 'Cerrada',
        'anulada' => 'Anulada',
        'fusionada' => 'Fusionada',
    ];

    protected $table = 'cuentas';

    /**
     * Solo lo que escribe quien abre la cuenta. El resto (numero, estado, version, mesero, cierre
     * y anulación) lo fija GestionarCuentas con forceFill.
     */
    protected $fillable = [
        'comensales',
        'nota',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'version' => 'integer',
            'comensales' => 'integer',
            'abierta_at' => 'datetime',
            'cerrada_at' => 'datetime',
            'anulada_at' => 'datetime',
        ];
    }

    public function scopeAbiertas(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('estado'), self::ESTADOS_ABIERTOS);
    }

    public function estaAbierta(): bool
    {
        return in_array($this->estado, self::ESTADOS_ABIERTOS, true);
    }

    public function estadoEtiqueta(): string
    {
        return self::ESTADO_ETIQUETAS[$this->estado] ?? ucfirst((string) $this->estado);
    }

    /**
     * Minutos desde que se abrió (hasta que terminó, si ya no está abierta).
     */
    public function minutosAbierta(): int
    {
        $fin = $this->cerrada_at ?? $this->anulada_at ?? now();

        return max(0, (int) $this->abierta_at->diffInMinutes($fin));
    }

    /**
     * "Menos de 1 min", "45 min" o "2 h 05 min".
     */
    public function tiempoAbierta(): string
    {
        $minutos = $this->minutosAbierta();

        return match (true) {
            $minutos < 1 => 'Menos de 1 min',
            $minutos < 60 => "{$minutos} min",
            default => intdiv($minutos, 60).' h '.str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT).' min',
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function mesero(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mesero_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    /**
     * @return BelongsTo<Cuenta, $this>
     */
    public function fusionadaEn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fusionada_en_id');
    }

    /**
     * Todas las mesas por las que pasó la cuenta.
     *
     * @return HasMany<OcupacionMesa, $this>
     */
    public function ocupaciones(): HasMany
    {
        return $this->hasMany(OcupacionMesa::class);
    }

    /**
     * Las mesas que ocupa ahora, en el orden en que entraron.
     *
     * @return HasMany<OcupacionMesa, $this>
     */
    public function ocupacionesAbiertas(): HasMany
    {
        return $this->ocupaciones()->whereNull('salida_at')->orderBy('id');
    }
}
