<?php

namespace App\Http\Requests\Carta;

use App\Models\CategoriaProducto;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear o editar una categoría de la carta.
 * Una subcategoría cuelga de una categoría de primer nivel del mismo restaurante (un solo nivel extra).
 */
class CategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $categoria = $this->categoria();

        return $categoria
            ? $this->user()->can('update', $categoria)
            : $this->user()->can('create', CategoriaProducto::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'parent_id' => match (true) {
                ! $this->filled('parent_id') => null,
                is_numeric($this->input('parent_id')) => (int) $this->input('parent_id'),
                default => $this->input('parent_id'),
            },
            'activa' => $this->boolean('activa'),
            'con_fotos' => $this->boolean('con_fotos'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categorias_producto', 'id')
                    ->where('restaurante_id', $this->user()->restaurante_id)
                    ->whereNull('parent_id'),
                Rule::notIn(array_filter([$this->categoria()?->id])),
            ],
            'activa' => ['boolean'],
            'con_fotos' => ['boolean'],
        ];
    }

    /**
     * Una categoría que ya tiene subcategorías no puede volverse subcategoría.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $categoria = $this->categoria();

                if ($categoria && $this->input('parent_id') !== null && $categoria->hijas()->exists()) {
                    $validator->errors()->add('parent_id', 'Esta categoría tiene subcategorías, así que no puede ir dentro de otra.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre de la categoría.',
            'nombre.max' => 'El nombre admite hasta 80 caracteres.',
            'descripcion.max' => 'La descripción admite hasta 255 caracteres.',
            'parent_id.exists' => 'Elige una categoría principal de tu carta.',
            'parent_id.not_in' => 'Una categoría no puede ir dentro de sí misma.',
        ];
    }

    private function categoria(): ?CategoriaProducto
    {
        $categoria = $this->route('categoria');

        return $categoria instanceof CategoriaProducto ? $categoria : null;
    }
}
