<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opcion extends Model
{
    protected $table = 'opciones';

    protected $fillable = [
        'grupo_id',
        'nombre',
        'precio_extra',
        'disponible',
        'orden',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_extra' => 'decimal:2',
            'disponible' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<GrupoOpcion, $this>
     */
    public function grupo(): BelongsTo
    {
        return $this->belongsTo(GrupoOpcion::class, 'grupo_id');
    }

    /**
     * @return HasMany<PedidoItemOpcion, $this>
     */
    public function pedidoItemOpciones(): HasMany
    {
        return $this->hasMany(PedidoItemOpcion::class);
    }
}
