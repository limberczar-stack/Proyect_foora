<?php

namespace App\Http\Requests\Superadmin;

use App\Models\SuscripcionPago;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RegistrarPagoRequest extends SuperadminRequest
{
    public const MONTO_MAXIMO = 99999.99;

    public const MESES_MAXIMOS = 36;

    protected function prepareForValidation(): void
    {
        $monto = $this->input('monto');
        $referencia = $this->input('referencia');

        $this->merge([
            'monto' => is_string($monto) ? str_replace(',', '.', trim($monto)) : $monto,
            'referencia' => is_string($referencia) && trim($referencia) !== '' ? trim($referencia) : null,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'monto' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.self::MONTO_MAXIMO],
            'metodo' => ['required', 'string', Rule::in(SuscripcionPago::METODOS)],
            'referencia' => ['nullable', 'string', 'max:80'],
            'periodo_desde' => ['required', 'date_format:Y-m-d'],
            'periodo_hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:periodo_desde'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monto.required' => 'Escribe el monto.',
            'monto.numeric' => 'El monto debe ser un número, por ejemplo 79.00.',
            'monto.decimal' => 'El monto admite hasta 2 decimales.',
            'monto.min' => 'El monto debe ser mayor que 0.',
            'monto.max' => 'El monto admite hasta S/ '.number_format(self::MONTO_MAXIMO, 2).'.',
            'metodo.required' => 'Elige cómo pagó.',
            'metodo.in' => 'Elige un método de la lista.',
            'referencia.max' => 'La referencia admite hasta 80 caracteres.',
            'periodo_desde.required' => 'Indica desde cuándo cubre el pago.',
            'periodo_desde.date_format' => 'La fecha de inicio no es válida.',
            'periodo_hasta.required' => 'Indica hasta cuándo cubre el pago.',
            'periodo_hasta.date_format' => 'La fecha de fin no es válida.',
            'periodo_hasta.after_or_equal' => 'El fin del periodo no puede ser anterior al inicio.',
        ];
    }

    /**
     * Un periodo enorme casi siempre es un error de tipeo en el año, y extendería el vencimiento por años.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['periodo_desde', 'periodo_hasta'])) {
                    return;
                }

                $limite = Carbon::parse($this->input('periodo_desde'))->addMonths(self::MESES_MAXIMOS);

                if (Carbon::parse($this->input('periodo_hasta'))->gt($limite)) {
                    $validator->errors()->add('periodo_hasta', 'El periodo admite hasta '.self::MESES_MAXIMOS.' meses. Revisa el año.');
                }
            },
        ];
    }
}
