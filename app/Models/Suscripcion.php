<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Database\Factories\SuscripcionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Suscripcion extends Model
{
    /** @use HasFactory<SuscripcionFactory> */
    use BelongsToRestaurante, HasFactory;

    /** Días de prueba gratis al dar de alta un local. */
    public const DIAS_PRUEBA = 30;

    /** Días que un local sigue operando después de vencer. */
    public const DIAS_GRACIA = 5;

    /** Con cuántos días de anticipación se avisa en el panel que está por vencer. */
    public const DIAS_AVISO = 7;

    /**
     * @var array<string, string>
     */
    public const ESTADOS = [
        'prueba' => 'En prueba',
        'activa' => 'Activa',
        'vencida' => 'Vencida',
        'cancelada' => 'Cancelada',
    ];

    protected $table = 'suscripciones';

    protected $fillable = [
        'restaurante_id',
        'plan_id',
        'estado',
        'inicia_el',
        'vence_el',
        'precio_pactado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inicia_el' => 'date',
            'vence_el' => 'date',
            'precio_pactado' => 'decimal:2',
        ];
    }

    /**
     * El estado que corresponde a la fecha dada (Y-m-d, en la zona del local): una suscripción cuyo
     * vence_el ya pasó está vencida aunque el comando diario todavía no la haya marcado.
     */
    public function estadoEl(string $fecha): string
    {
        if (in_array($this->estado, ['prueba', 'activa'], true) && $this->vence_el->toDateString() < $fecha) {
            return 'vencida';
        }

        return $this->estado;
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<SuscripcionPago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(SuscripcionPago::class);
    }
}
