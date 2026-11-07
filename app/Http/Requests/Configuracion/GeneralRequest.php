<?php

namespace App\Http\Requests\Configuracion;

/**
 * Datos del local que ve el cliente. El slug no se edita: cambiarlo rompería los enlaces ya compartidos.
 */
class GeneralRequest extends ConfiguracionRequest
{
    public const LOGO_MAX_KB = 2048;

    protected function prepareForValidation(): void
    {
        $this->merge([
            'telefono' => $this->filled('telefono') ? $this->celularNormalizado($this->input('telefono')) : null,
            'rubro_id' => $this->filled('rubro_id') ? $this->input('rubro_id') : null,
            'quitar_logo' => $this->boolean('quitar_logo'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'telefono' => ['nullable', 'string', 'regex:/\A9[0-9]{8}\z/'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['required', 'string', 'max:80'],
            'rubro_id' => ['nullable', 'integer', 'exists:rubros,id'],
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.self::LOGO_MAX_KB],
            'quitar_logo' => ['boolean'],
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
            'descripcion.max' => 'La descripción admite hasta 1000 caracteres.',
            'telefono.regex' => 'Escribe un celular peruano de 9 dígitos que empiece con 9.',
            'email.email' => 'Escribe un correo válido, por ejemplo local@correo.pe.',
            'email.max' => 'El correo admite hasta 150 caracteres.',
            'direccion.max' => 'La dirección admite hasta 255 caracteres.',
            'ciudad.required' => 'Escribe la ciudad.',
            'ciudad.max' => 'La ciudad admite hasta 80 caracteres.',
            'rubro_id.exists' => 'Elige un rubro de la lista.',
            'rubro_id.integer' => 'Elige un rubro de la lista.',
            'logo.file' => 'No pudimos leer el archivo. Prueba con otra imagen.',
            'logo.uploaded' => 'No pudimos subir el logo. Revisa que pese menos de 2 MB.',
            'logo.mimes' => 'El logo debe ser una imagen JPG, PNG o WEBP.',
            'logo.mimetypes' => 'El logo debe ser una imagen JPG, PNG o WEBP.',
            'logo.max' => 'El logo debe pesar como máximo 2 MB.',
        ];
    }
}
