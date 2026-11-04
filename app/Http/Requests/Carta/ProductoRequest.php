<?php

namespace App\Http\Requests\Carta;

use App\Models\Producto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear o editar un producto de la carta (etapa 1: precio único o variantes).
 */
class ProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $producto = $this->producto();

        return $producto
            ? $this->user()->can('update', $producto)
            : $this->user()->can('create', Producto::class);
    }

    protected function prepareForValidation(): void
    {
        $variantes = collect($this->input('variantes', []))
            ->filter(fn ($fila): bool => is_array($fila) && (filled($fila['nombre'] ?? null) || filled($fila['precio'] ?? null)))
            ->map(fn (array $fila): array => [
                'id' => filled($fila['id'] ?? null) ? (int) $fila['id'] : null,
                'nombre' => trim((string) ($fila['nombre'] ?? '')),
                'precio' => $this->normalizarPrecio($fila['precio'] ?? null),
                'disponible' => filter_var($fila['disponible'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->values()
            ->all();

        $this->merge([
            'precio' => $this->normalizarPrecio($this->input('precio')),
            'disponible' => $this->boolean('disponible'),
            'destacado' => $this->boolean('destacado'),
            'quitar_imagen' => $this->boolean('quitar_imagen'),
            'etiquetas' => array_values((array) $this->input('etiquetas', [])),
            'variantes' => $variantes,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $precio = ['decimal:0,2', 'gt:0', 'max:'.Producto::PRECIO_MAXIMO];

        return [
            'nombre' => ['required', 'string', 'max:120'],
            'categoria_id' => [
                'required',
                'integer',
                Rule::exists('categorias_producto', 'id')->where('restaurante_id', $this->user()->restaurante_id),
            ],
            'tipo' => ['required', Rule::in(array_keys(Producto::TIPOS_EDITABLES))],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'precio' => ['exclude_unless:tipo,simple', 'required', ...$precio],
            'imagen' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'quitar_imagen' => ['boolean'],
            'estacion' => ['required', Rule::in(['cocina', 'barra'])],
            'destacado' => ['boolean'],
            'disponible' => ['boolean'],
            'etiquetas' => ['array'],
            'etiquetas.*' => ['string', Rule::in(array_keys(Producto::ETIQUETAS))],
            'variantes' => ['exclude_unless:tipo,variantes', 'array', 'min:2'],
            'variantes.*.id' => ['nullable', 'integer'],
            'variantes.*.nombre' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'variantes.*.precio' => ['required', ...$precio],
            'variantes.*.disponible' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maximo = number_format(Producto::PRECIO_MAXIMO, 2);

        return [
            'nombre.required' => 'Escribe el nombre del producto.',
            'nombre.max' => 'El nombre admite hasta 120 caracteres.',
            'categoria_id.required' => 'Elige una categoría.',
            'categoria_id.exists' => 'Elige una categoría de tu carta.',
            'tipo.in' => 'Elige si tiene precio único o variantes.',
            'descripcion.max' => 'La descripción admite hasta 1000 caracteres.',
            'precio.required' => 'Escribe el precio.',
            'precio.decimal' => 'El precio admite hasta 2 decimales, por ejemplo 18.50.',
            'precio.gt' => 'El precio debe ser mayor a S/ 0.00.',
            'precio.max' => "El precio máximo es S/ {$maximo}.",
            'imagen.file' => 'No se pudo subir la imagen.',
            'imagen.mimes' => 'La imagen debe ser JPG, PNG o WEBP.',
            'imagen.max' => 'La imagen pesa más de 2 MB.',
            'imagen.uploaded' => 'No se pudo subir la imagen. Revisa que pese menos de 2 MB.',
            'estacion.in' => 'Elige cocina o barra.',
            'etiquetas.*.in' => 'Una de las etiquetas no es válida.',
            'variantes.min' => 'Un producto con variantes necesita al menos 2 variantes.',
            'variantes.array' => 'Un producto con variantes necesita al menos 2 variantes.',
            'variantes.*.nombre.required' => 'Cada variante necesita un nombre.',
            'variantes.*.nombre.max' => 'El nombre de la variante admite hasta 60 caracteres.',
            'variantes.*.nombre.distinct' => 'Hay dos variantes con el mismo nombre.',
            'variantes.*.precio.required' => 'Cada variante necesita un precio.',
            'variantes.*.precio.decimal' => 'El precio admite hasta 2 decimales.',
            'variantes.*.precio.gt' => 'El precio debe ser mayor a S/ 0.00.',
            'variantes.*.precio.max' => "El precio máximo es S/ {$maximo}.",
        ];
    }

    /**
     * Acepta "18,50" o "18.50" (teclados de celular en español usan coma).
     */
    private function normalizarPrecio(mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }

        $valor = trim(str_replace(',', '.', $valor));

        return $valor === '' ? null : $valor;
    }

    private function producto(): ?Producto
    {
        $producto = $this->route('producto');

        return $producto instanceof Producto ? $producto : null;
    }
}
