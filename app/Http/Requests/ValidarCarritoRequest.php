<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Líneas del carrito público. Solo se admiten ids y cantidades: cualquier otro campo,
 * como un precio, queda fuera de validated(). La cantidad la acota CalculadorLineaPedido,
 * para responder el error en su línea y no en toda la petición.
 */
class ValidarCarritoRequest extends FormRequest
{
    public const LINEAS_MAXIMAS = 30;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'lineas' => ['present', 'array', 'max:'.self::LINEAS_MAXIMAS],
            'lineas.*.producto_id' => ['required', 'integer', 'min:1'],
            'lineas.*.variante_id' => ['nullable', 'integer', 'min:1'],
            'lineas.*.opciones' => ['array'],
            'lineas.*.opciones.*' => ['integer', 'min:1'],
            'lineas.*.cantidad' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lineas.max' => 'El carrito admite hasta '.self::LINEAS_MAXIMAS.' productos distintos.',
        ];
    }

    /**
     * @return list<array{producto_id: int, variante_id: ?int, opciones: list<int>, cantidad: int}>
     */
    public function lineas(): array
    {
        return array_map(fn (array $linea): array => [
            'producto_id' => (int) $linea['producto_id'],
            'variante_id' => isset($linea['variante_id']) ? (int) $linea['variante_id'] : null,
            'opciones' => array_map(intval(...), array_values($linea['opciones'] ?? [])),
            'cantidad' => (int) $linea['cantidad'],
        ], array_values($this->validated('lineas')));
    }
}
