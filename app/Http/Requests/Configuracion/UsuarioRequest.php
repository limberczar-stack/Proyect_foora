<?php

namespace App\Http\Requests\Configuracion;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Crear o editar un usuario del local.
 *
 * - El rol solo puede ser uno del personal: nunca superadmin ni cliente.
 * - El restaurante sale de la sesión del admin; el correo se fija al crear.
 * - Al editar, un usuario de otro local responde 404 antes de mirar los datos.
 */
class UsuarioRequest extends FormRequest
{
    private ?User $usuarioObjetivo = null;

    public function authorize(): bool
    {
        Gate::authorize('viewAny', User::class);

        if ($this->esEdicion()) {
            Gate::authorize('update', $this->usuarioObjetivo());
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $telefono = $this->input('telefono');

        $this->merge([
            'telefono' => is_string($telefono) && trim($telefono) !== ''
                ? preg_replace('/\A(\+?51)(9\d{8})\z/', '$2', (string) preg_replace('/[\s\-().]/', '', $telefono))
                : null,
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $reglas = [
            'name' => ['required', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'regex:/\A9[0-9]{8}\z/'],
            'rol' => ['required', 'string', Rule::in(User::ROLES_PERSONAL)],
        ];

        if (! $this->esEdicion()) {
            $reglas['email'] = ['required', 'string', 'email', 'max:150', Rule::unique('users', 'email')];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre de la persona.',
            'name.max' => 'El nombre admite hasta 120 caracteres.',
            'email.required' => 'Escribe el correo con el que entrará al panel.',
            'email.email' => 'Escribe un correo válido, por ejemplo nombre@correo.pe.',
            'email.max' => 'El correo admite hasta 150 caracteres.',
            'email.unique' => 'Ya existe una cuenta con ese correo. Usa otro.',
            'telefono.regex' => 'Escribe un celular peruano de 9 dígitos que empiece con 9.',
            'rol.required' => 'Elige un rol.',
            'rol.in' => 'Elige un rol válido para el personal del local.',
        ];
    }

    public function esEdicion(): bool
    {
        return $this->route('usuario') !== null;
    }

    /**
     * El usuario que se edita, buscado solo dentro del local de quien actúa.
     */
    public function usuarioObjetivo(): User
    {
        return $this->usuarioObjetivo ??= self::buscarEnElLocal($this->user(), $this->route('usuario'));
    }

    /**
     * Busca un usuario del personal del mismo local, o responde 404.
     */
    public static function buscarEnElLocal(User $actor, mixed $id): User
    {
        abort_unless(is_numeric($id), 404);

        return User::query()
            ->where('restaurante_id', $actor->restaurante_id)
            ->whereIn('rol', User::ROLES_PERSONAL)
            ->findOrFail((int) $id);
    }
}
