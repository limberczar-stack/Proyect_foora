<?php

namespace App\Http\Requests\Cuentas;

use App\Http\Requests\PedidoRequest;
use App\Http\Requests\ValidarCarritoRequest;
use App\Models\Cuenta;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Una ronda del salón: solo ids, cantidades, opciones y observación, más la clave de idempotencia.
 * Los mismos límites que el carrito público: 30 líneas y 200 caracteres de observación; la cantidad
 * (1 a 50) y la disponibilidad las revisa CalculadorLineaPedido línea por línea, para que el borrador
 * marque cuál falló sin perder las demás.
 *
 * Con «validar» (sin clave) solo se calculan los montos del borrador; no se guarda nada.
 */
class EnviarRondaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cuenta = $this->route('cuenta');

        return $cuenta instanceof Cuenta && $this->user()->can('update', $cuenta);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $reglas = [
            'lineas' => ['required', 'array', 'min:1', 'max:'.ValidarCarritoRequest::LINEAS_MAXIMAS],
            'lineas.*.producto_id' => ['required', 'integer', 'min:1'],
            'lineas.*.variante_id' => ['nullable', 'integer', 'min:1'],
            'lineas.*.opciones' => ['array'],
            'lineas.*.opciones.*' => ['integer', 'min:1'],
            'lineas.*.cantidad' => ['required', 'integer'],
            'lineas.*.observacion' => ['nullable', 'string', 'max:'.PedidoRequest::OBSERVACION_MAXIMA],
        ];

        if (! $this->routeIs('panel.mesas.cuentas.pedido.validar')) {
            $reglas['idempotency_key'] = ['required', 'string', 'regex:/\A[A-Za-z0-9-]{16,64}\z/'];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lineas.required' => 'Agrega al menos un producto a la ronda.',
            'lineas.min' => 'Agrega al menos un producto a la ronda.',
            'lineas.max' => 'Una ronda admite hasta '.ValidarCarritoRequest::LINEAS_MAXIMAS.' productos distintos.',
            'lineas.*.observacion.max' => 'Las observaciones admiten hasta '.PedidoRequest::OBSERVACION_MAXIMA.' caracteres.',
            'idempotency_key.*' => 'Vuelve a abrir la cuenta e inténtalo otra vez.',
        ];
    }

    /**
     * @return list<array{producto_id: int, variante_id: ?int, opciones: list<int>, cantidad: int, observacion: ?string}>
     */
    public function lineas(): array
    {
        return array_map(fn (array $linea): array => [
            'producto_id' => (int) $linea['producto_id'],
            'variante_id' => isset($linea['variante_id']) ? (int) $linea['variante_id'] : null,
            'opciones' => array_map(intval(...), array_values($linea['opciones'] ?? [])),
            'cantidad' => (int) $linea['cantidad'],
            'observacion' => isset($linea['observacion']) && trim((string) $linea['observacion']) !== '' ? trim((string) $linea['observacion']) : null,
        ], array_values($this->validated('lineas')));
    }
}
