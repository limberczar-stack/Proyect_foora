<?php

namespace App\Http\Requests\Mesas;

use App\Models\Area;
use App\Models\Mesa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear o renombrar un área del salón. El nombre es único en el local (índice uq_area).
 */
class AreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $area = $this->area();

        return $area
            ? $this->user()->can('update', $area)
            : $this->user()->can('gestionar', Mesa::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nombre'))) {
            $this->merge(['nombre' => preg_replace('/\s+/u', ' ', trim($this->input('nombre')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => [
                'required',
                'string',
                'max:60',
                Rule::unique('areas', 'nombre')
                    ->where('restaurante_id', $this->user()->restaurante_id)
                    ->ignore($this->area()?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre del área.',
            'nombre.max' => 'El nombre admite hasta 60 caracteres.',
            'nombre.unique' => 'Ya tienes un área con ese nombre.',
        ];
    }

    private function area(): ?Area
    {
        $area = $this->route('area');

        return $area instanceof Area ? $area : null;
    }
}
