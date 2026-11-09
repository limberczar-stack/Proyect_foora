<?php

namespace App\Http\Requests\Superadmin;

use Illuminate\Validation\Rule;

class CambiarPlanRequest extends SuperadminRequest
{
    public const PRECIO_MAXIMO = 9999.99;

    protected function prepareForValidation(): void
    {
        $precio = $this->input('precio_pactado');

        $this->merge(['precio_pactado' => is_string($precio) ? str_replace(',', '.', trim($precio)) : $precio]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('planes', 'id')->where('activo', true)],
            'precio_pactado' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::PRECIO_MAXIMO],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.required' => 'Elige un plan.',
            'plan_id.integer' => 'Elige un plan de la lista.',
            'plan_id.exists' => 'Elige un plan de la lista.',
            'precio_pactado.required' => 'Escribe el precio pactado (0 si no paga).',
            'precio_pactado.numeric' => 'El precio debe ser un monto, por ejemplo 79.00.',
            'precio_pactado.decimal' => 'El precio admite hasta 2 decimales.',
            'precio_pactado.min' => 'El precio no puede ser negativo.',
            'precio_pactado.max' => 'El precio admite hasta S/ '.number_format(self::PRECIO_MAXIMO, 2).'.',
        ];
    }
}
