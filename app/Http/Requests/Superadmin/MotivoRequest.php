<?php

namespace App\Http\Requests\Superadmin;

/**
 * Suspender, reactivar, cancelar o anular: siempre con un motivo, que queda en la auditoría.
 */
class MotivoRequest extends SuperadminRequest
{
    protected function prepareForValidation(): void
    {
        $motivo = $this->input('motivo');

        $this->merge(['motivo' => is_string($motivo) ? trim($motivo) : $motivo]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:5', 'max:200']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Escribe el motivo.',
            'motivo.min' => 'Explica el motivo en al menos 5 caracteres.',
            'motivo.max' => 'El motivo admite hasta 200 caracteres.',
        ];
    }
}
