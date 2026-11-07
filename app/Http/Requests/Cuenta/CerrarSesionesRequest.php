<?php

namespace App\Http\Requests\Cuenta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cerrar las sesiones abiertas en otros dispositivos: se confirma con la contraseña actual.
 */
class CerrarSesionesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'password_actual' => ['required', 'string', 'current_password:web'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password_actual.required' => 'Escribe tu contraseña para confirmar.',
            'password_actual.current_password' => 'La contraseña no es correcta.',
        ];
    }
}
