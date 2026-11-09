<?php

namespace App\Http\Requests\Superadmin;

/**
 * Datos del local editados por el superadmin. El slug no está aquí: no se cambia después del alta.
 */
class DatosRestauranteRequest extends SuperadminRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'telefono' => $this->celularNormalizado($this->input('telefono')),
            'rubro_id' => $this->filled('rubro_id') ? $this->input('rubro_id') : null,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
            'rubro_id' => ['nullable', 'integer', 'exists:rubros,id'],
            'ciudad' => ['required', 'string', 'max:80'],
            'telefono' => ['nullable', 'string', 'regex:/\A9[0-9]{8}\z/'],
            'direccion' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre del local.',
            'nombre.max' => 'El nombre admite hasta 120 caracteres.',
            'rubro_id.exists' => 'Elige un rubro de la lista.',
            'rubro_id.integer' => 'Elige un rubro de la lista.',
            'ciudad.required' => 'Escribe la ciudad.',
            'ciudad.max' => 'La ciudad admite hasta 80 caracteres.',
            'telefono.regex' => 'Escribe un celular peruano de 9 dígitos que empiece con 9.',
            'direccion.max' => 'La dirección admite hasta 255 caracteres.',
        ];
    }
}
