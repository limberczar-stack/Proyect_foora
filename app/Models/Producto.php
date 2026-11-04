<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Database\Factories\ProductoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Producto extends Model
{
    /** @use HasFactory<ProductoFactory> */
    use BelongsToRestaurante, HasFactory, SoftDeletes;

    /**
     * Etiquetas disponibles en la carta: clave => texto visible.
     *
     * @var array<string, string>
     */
    public const ETIQUETAS = [
        'vegetariano' => 'Vegetariano',
        'picante' => 'Picante',
        'nuevo' => 'Nuevo',
        'sin_gluten' => 'Sin gluten',
    ];

    /**
     * Tipos que se pueden editar en la etapa 1 (los combos llegan en la etapa 2).
     *
     * @var array<string, string>
     */
    public const TIPOS_EDITABLES = [
        'simple' => 'Precio único',
        'variantes' => 'Con variantes',
    ];

    public const PRECIO_MAXIMO = 9999.99;

    protected $table = 'productos';

    protected $fillable = [
        'restaurante_id',
        'categoria_id',
        'tipo',
        'personas',
        'nombre',
        'descripcion',
        'imagen',
        'precio',
        'disponible',
        'destacado',
        'estacion',
        'etiquetas',
        'orden',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'personas' => 'integer',
            'disponible' => 'boolean',
            'destacado' => 'boolean',
            'etiquetas' => 'array',
            'orden' => 'integer',
        ];
    }

    public function tieneVariantes(): bool
    {
        return $this->tipo === 'variantes';
    }

    public function imagenUrl(): ?string
    {
        return $this->imagen ? Storage::disk('public')->url($this->imagen) : null;
    }

    /**
     * La categoría exige foto salvo que tenga con_fotos = 0 (bebidas, vinos…).
     * Un producto sin categoría sí la exige.
     */
    public function requiereImagen(): bool
    {
        return $this->categoria?->con_fotos ?? true;
    }

    /**
     * Le falta descripción, o imagen cuando su categoría la exige.
     */
    public function estaIncompleto(): bool
    {
        return blank($this->descripcion) || (blank($this->imagen) && $this->requiereImagen());
    }

    /**
     * Productos que dejan la carta incompleta. Base de la futura alerta "Menú incompleto".
     *
     * @param  Builder<Producto>  $query
     */
    public function scopeIncompletos(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('descripcion')
                ->orWhere('descripcion', '')
                ->orWhere(function (Builder $query): void {
                    $query->where(fn (Builder $q) => $q->whereNull('imagen')->orWhere('imagen', ''))
                        ->where(function (Builder $query): void {
                            $query->whereNull('categoria_id')
                                ->orWhereHas('categoria', fn (Builder $categoria) => $categoria->where('con_fotos', true));
                        });
                });
        });
    }

    /**
     * El precio "desde": el menor de sus variantes.
     */
    public function recalcularPrecio(): void
    {
        if (! $this->tieneVariantes()) {
            return;
        }

        $minimo = $this->variantes()->min('precio');

        if ($minimo !== null) {
            $this->forceFill(['precio' => $minimo])->saveQuietly();
        }
    }

    /**
     * @return BelongsTo<CategoriaProducto, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'categoria_id');
    }

    /**
     * @return HasMany<ProductoVariante, $this>
     */
    public function variantes(): HasMany
    {
        return $this->hasMany(ProductoVariante::class)->orderBy('orden')->orderBy('id');
    }

    /**
     * @return BelongsToMany<GrupoOpcion, $this>
     */
    public function gruposOpcion(): BelongsToMany
    {
        return $this->belongsToMany(GrupoOpcion::class, 'producto_grupos_opcion', 'producto_id', 'grupo_id')
            ->withPivot('orden')
            ->orderByPivot('orden');
    }

    /**
     * @return HasMany<PedidoItem, $this>
     */
    public function pedidoItems(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }

    /**
     * @return BelongsToMany<Promocion, $this>
     */
    public function promociones(): BelongsToMany
    {
        return $this->belongsToMany(Promocion::class, 'promocion_productos', 'producto_id', 'promocion_id');
    }
}
