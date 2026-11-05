<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Database\Factories\GrupoOpcionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo de opciones reutilizable entre productos: "Elige tu guarnición", "Adicionales"…
 */
class GrupoOpcion extends Model
{
    /** @use HasFactory<GrupoOpcionFactory> */
    use BelongsToRestaurante, HasFactory;

    /**
     * Tipos de grupo: clave => texto visible.
     *
     * @var array<string, string>
     */
    public const TIPOS = [
        'unica' => 'Elección única',
        'multiple' => 'Elección múltiple',
    ];

    /** Tope de las columnas TINYINT min_opciones y max_opciones. */
    public const LIMITE_OPCIONES = 255;

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

    public function minimoElegible(): int
    {
        return max($this->min_opciones, $this->obligatorio ? 1 : 0);
    }

    /**
     * Null significa sin límite.
     */
    public function maximoElegible(): ?int
    {
        return $this->tipo === 'unica' ? 1 : $this->max_opciones;
    }

    /**
     * La regla en palabras del personal: "Elige 1", "Elige de 1 a 3"…
     */
    public function descripcionRegla(): string
    {
        $minimo = $this->minimoElegible();
        $maximo = $this->maximoElegible();

        return match (true) {
            $maximo === null && $minimo === 0 => 'Elige las que quieras',
            $maximo === null => "Elige al menos {$minimo}",
            $minimo === 0 => "Elige hasta {$maximo}",
            $minimo === $maximo => "Elige {$minimo}",
            default => "Elige de {$minimo} a {$maximo}",
        };
    }

    /**
     * El plural que deduce Laravel para {opcion} sería "opcions".
     *
     * @param  string  $childType
     */
    protected function childRouteBindingRelationshipName($childType): string
    {
        return $childType === 'opcion' ? 'opciones' : parent::childRouteBindingRelationshipName($childType);
    }

    /**
     * @return HasMany<Opcion, $this>
     */
    public function opciones(): HasMany
    {
        return $this->hasMany(Opcion::class, 'grupo_id')->orderBy('orden')->orderBy('id');
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
