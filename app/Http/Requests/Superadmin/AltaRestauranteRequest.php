<?php

namespace App\Http\Requests\Superadmin;

use App\Rules\SlugDisponible;
use Illuminate\Validation\Rule;

/**
 * Alta de un local: sus datos básicos, el plan y su primer admin.
 */
class AltaRestauranteRequest extends SuperadminRequest
{
    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');
        $email = $this->input('admin_email');

        $this->merge([
            'slug' => is_string($slug) ? trim($slug) : $slug,
            'telefono' => $this->celularNormalizado($this->input('telefono')),
            'admin_email' => is_string($email) ? mb_strtolower(trim($email)) : $email,
            'rubro_id' => $this->filled('rubro_id') ? $this->input('rubro_id') : null,
        ]);
    }

    /**
     * El slug eliminado sigue ocupado: sus enlaces viejos no deben llevar a otro local.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', new SlugDisponible, Rule::unique('restaurantes', 'slug')],
            'rubro_id' => ['nullable', 'integer', 'exists:rubros,id'],
            'ciudad' => ['required', 'string', 'max:80'],
            'telefono' => ['nullable', 'string', 'regex:/\A9[0-9]{8}\z/'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'plan_id' => ['required', 'integer', Rule::exists('planes', 'id')->where('activo', true)],
            'admin_nombre' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'string', 'email', 'max:150', Rule::unique('users', 'email')],
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
            'slug.required' => 'Escribe la dirección de la vitrina.',
            'slug.unique' => 'Otro restaurante ya usa esta dirección.',
            'rubro_id.exists' => 'Elige un rubro de la lista.',
            'rubro_id.integer' => 'Elige un rubro de la lista.',
            'ciudad.required' => 'Escribe la ciudad.',
            'ciudad.max' => 'La ciudad admite hasta 80 caracteres.',
            'telefono.regex' => 'Escribe un celular peruano de 9 dígitos que empiece con 9.',
            'direccion.max' => 'La dirección admite hasta 255 caracteres.',
            'plan_id.required' => 'Elige un plan.',
            'plan_id.integer' => 'Elige un plan de la lista.',
            'plan_id.exists' => 'Elige un plan de la lista.',
            'admin_nombre.required' => 'Escribe el nombre del administrador.',
            'admin_nombre.max' => 'El nombre admite hasta 120 caracteres.',
            'admin_email.required' => 'Escribe el correo con el que entrará al panel.',
            'admin_email.email' => 'Escribe un correo válido, por ejemplo nombre@correo.pe.',
            'admin_email.max' => 'El correo admite hasta 150 caracteres.',
            'admin_email.unique' => 'Ya existe una cuenta con ese correo. Usa otro.',
        ];
    }
}
