<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo de opciones reutilizable entre productos: "Elige tu guarnición", "Adicionales"…
 * (El editor llega en la etapa 2.)
 */
class GrupoOpcion extends Model
{
    use BelongsToRestaurante;

    protected $table = 'grupos_opcion';

    protected $fillable = [
        'restaurante_id',
        'nombre',
        'tipo',
        'obligatorio',
        'min_opciones',
        'max_opciones',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
            'min_opciones' => 'integer',
            'max_opciones' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Opcion, $this>
     */
    public function opciones(): HasMany
    {
        return $this->hasMany(Opcion::class, 'grupo_id')->orderBy('orden');
    }

    /**
     * @return BelongsToMany<Producto, $this>
     */
    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'producto_grupos_opcion', 'grupo_id', 'producto_id')
            ->withPivot('orden');
    }
}
