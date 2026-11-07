<?php

namespace App\Http\Requests\Panel;

use App\Models\Pedido;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de estado desde el panel. "desde" es el estado que veía quien actúa: si ya no coincide,
 * el servicio responde que el pedido cambió.
 */
class CambiarEstadoPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pedido = $this->route('pedido');

        return $pedido instanceof Pedido && $this->user()->can('cambiarEstado', [$pedido, (string) $this->input('destino')]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['cobrado' => $this->boolean('cobrado')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'desde' => ['required', Rule::in(array_keys(Pedido::ESTADOS))],
            'destino' => ['required', Rule::in(array_keys(Pedido::ESTADOS))],
            'motivo' => ['exclude_unless:destino,cancelado', 'required', Rule::in(array_keys(Pedido::MOTIVOS_CANCELACION))],
            'detalle' => ['exclude_unless:destino,cancelado', 'nullable', 'string', 'max:255'],
            'minutos' => ['exclude_unless:destino,en_camino', 'required', 'integer', 'min:1', 'max:'.Pedido::MINUTOS_ETA_MAXIMO],
            'cobrado' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Elige el motivo de la cancelación.',
            'motivo.in' => 'Elige el motivo de la cancelación.',
            'detalle.max' => 'El detalle admite hasta 255 caracteres.',
            'minutos.required' => 'Indica en cuántos minutos llega.',
            'minutos.integer' => 'Los minutos deben ser un número entero.',
            'minutos.min' => 'Los minutos deben ser al menos 1.',
            'minutos.max' => 'Los minutos admiten hasta '.Pedido::MINUTOS_ETA_MAXIMO.'.',
            'destino.in' => 'Ese estado no existe.',
            'desde.in' => 'Ese estado no existe.',
        ];
    }

    /**
     * @return array{motivo?: ?string, detalle?: ?string, minutos?: ?int, cobrado: bool}
     */
    public function datos(): array
    {
        $validados = $this->validated();

        return [
            'motivo' => $validados['motivo'] ?? null,
            'detalle' => filled($validados['detalle'] ?? null) ? trim($validados['detalle']) : null,
            'minutos' => isset($validados['minutos']) ? (int) $validados['minutos'] : null,
            'cobrado' => (bool) $validados['cobrado'],
        ];
    }
}
