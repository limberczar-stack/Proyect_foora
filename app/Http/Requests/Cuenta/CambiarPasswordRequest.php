<?php

namespace App\Http\Requests\Cuenta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Cambio de la propia contraseña: pide la actual y una nueva de al menos 8 caracteres, distinta de la actual.
 */
class CambiarPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'password_actual' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'max:72', 'different:password_actual', Password::min(8)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password_actual.required' => 'Escribe tu contraseña actual.',
            'password_actual.current_password' => 'La contraseña actual no es correcta.',
            'password.required' => 'Escribe la nueva contraseña.',
            'password.min' => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'password.max' => 'La nueva contraseña admite hasta 72 caracteres.',
            'password.confirmed' => 'Las dos contraseñas nuevas no coinciden.',
            'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
        ];
    }
}
