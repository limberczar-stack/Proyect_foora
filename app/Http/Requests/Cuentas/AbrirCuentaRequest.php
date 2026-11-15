<?php

namespace App\Http\Requests\Cuentas;

use App\Models\Cuenta;
use App\Services\GestionarCuentas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Abrir una cuenta: la mesa tocada y, si se unen, las demás. Que sigan disponibles lo revisa
 * GestionarCuentas con el local bloqueado (409 si ya no lo están).
 */
class AbrirCuentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('atender', Cuenta::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mesas' => ['required', 'array', 'min:1', 'max:'.GestionarCuentas::MESAS_MAXIMO],
            'mesas.*' => ['integer', 'distinct', Rule::exists('mesas', 'id')->where('restaurante_id', $this->user()->restaurante_id)],
            'comensales' => ['required', 'integer', 'between:1,'.Cuenta::COMENSALES_MAXIMO],
            'nota' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mesas.required' => 'Elige al menos una mesa.',
            'mesas.array' => 'Elige al menos una mesa.',
            'mesas.min' => 'Elige al menos una mesa.',
            'mesas.max' => 'Una cuenta lleva hasta '.GestionarCuentas::MESAS_MAXIMO.' mesas.',
            'mesas.*.integer' => 'Elige mesas de tu local.',
            'mesas.*.distinct' => 'No repitas la misma mesa.',
            'mesas.*.exists' => 'Elige mesas de tu local.',
            'comensales.required' => 'Indica cuántos comensales son.',
            'comensales.integer' => 'Los comensales deben ser un número entero.',
            'comensales.between' => 'Los comensales van de 1 a '.Cuenta::COMENSALES_MAXIMO.'.',
            'nota.max' => 'La nota admite hasta 255 caracteres.',
        ];
    }
}
