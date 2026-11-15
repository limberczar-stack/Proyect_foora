<?php

namespace App\Http\Requests\Mesas;

use App\Models\Mesa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear o editar una mesa. El área debe ser del mismo local. El estado, si llega, solo puede ser
 * disponible o fuera de servicio: ocupada y reservada nunca se fijan desde una petición.
 * Que el código no lo use otra mesa activa lo revisa GestionarMesas con el local bloqueado.
 */
class MesaRequest extends FormRequest
{
    /** Letras, números, espacios y guiones; empieza con letra o número. */
    public const PATRON_CODIGO = '/\A[\pL\pN][\pL\pN \-]*\z/u';

    public function authorize(): bool
    {
        $mesa = $this->mesa();

        return $mesa
            ? $this->user()->can('update', $mesa)
            : $this->user()->can('gestionar', Mesa::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('codigo'))) {
            $this->merge(['codigo' => preg_replace('/\s+/u', ' ', trim($this->input('codigo')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:'.Mesa::CODIGO_MAXIMO, 'regex:'.self::PATRON_CODIGO],
            'capacidad' => ['required', 'integer', 'between:'.Mesa::CAPACIDAD_MINIMA.','.Mesa::CAPACIDAD_MAXIMA],
            'area_id' => ['required', 'integer', Rule::exists('areas', 'id')->where('restaurante_id', $this->user()->restaurante_id)],
            'estado' => ['sometimes', 'string', Rule::in(Mesa::ESTADOS_MANUALES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::mensajes();
    }

    /**
     * @return array<string, string>
     */
    public static function mensajes(): array
    {
        return [
            'codigo.required' => 'Escribe el nombre o número de la mesa.',
            'codigo.max' => 'El nombre o número admite hasta '.Mesa::CODIGO_MAXIMO.' caracteres.',
            'codigo.regex' => 'Usa solo letras, números, espacios y guiones.',
            'capacidad.required' => 'Indica para cuántas personas es la mesa.',
            'capacidad.integer' => 'La capacidad debe ser un número entero.',
            'capacidad.between' => 'La capacidad va de '.Mesa::CAPACIDAD_MINIMA.' a '.Mesa::CAPACIDAD_MAXIMA.' personas.',
            'area_id.required' => 'Elige el área de la mesa.',
            'area_id.integer' => 'Elige un área de tu local.',
            'area_id.exists' => 'Elige un área de tu local.',
            'estado.in' => 'Solo puedes marcar una mesa como disponible o fuera de servicio.',
        ];
    }

    private function mesa(): ?Mesa
    {
        $mesa = $this->route('mesa');

        return $mesa instanceof Mesa ? $mesa : null;
    }
}
