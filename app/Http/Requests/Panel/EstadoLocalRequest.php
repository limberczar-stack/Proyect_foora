<?php

namespace App\Http\Requests\Panel;

use App\Models\Restaurante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Abrir o cerrar el local desde la barra lateral. Se envía el estado deseado (no un "alternar"),
 * así dos clics seguidos o dos pestañas abiertas no lo dejan al revés.
 */
class EstadoLocalRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('cambiarEstado', $this->user()->restaurante ?? new Restaurante);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['confirmar' => $this->boolean('confirmar')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::in(['abierto', 'cerrado'])],
            'confirmar' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estado.required' => 'Indica si el local queda abierto o cerrado.',
            'estado.in' => 'El estado debe ser abierto o cerrado.',
        ];
    }
}
