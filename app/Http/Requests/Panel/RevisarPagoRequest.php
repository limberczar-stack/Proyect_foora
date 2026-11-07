<?php

namespace App\Http\Requests\Panel;

use App\Models\Pedido;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevisarPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pedido = $this->route('pedido');

        return $pedido instanceof Pedido && $this->user()->can('revisarPago', $pedido);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'accion' => ['required', Rule::in(['validar', 'rechazar'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accion.required' => 'Indica si validas o rechazas el pago.',
            'accion.in' => 'Indica si validas o rechazas el pago.',
        ];
    }
}
