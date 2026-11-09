<?php

namespace App\Http\Requests\Superadmin;

class NotasInternasRequest extends SuperadminRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['notas_internas' => ['nullable', 'string', 'max:5000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['notas_internas.max' => 'Las notas admiten hasta 5000 caracteres.'];
    }
}
