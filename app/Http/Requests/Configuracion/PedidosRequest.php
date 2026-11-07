<?php

namespace App\Http\Requests\Configuracion;

use Illuminate\Contracts\Validation\Validator;

/**
 * Cómo recibe pedidos el local: modalidades, montos, tiempo de preparación y avisos.
 */
class PedidosRequest extends ConfiguracionRequest
{
    public const COSTO_DELIVERY_MAXIMO = 99.99;

    public const PEDIDO_MINIMO_MAXIMO = 999.99;

    public const TIEMPO_PREP_MINIMO = 5;

    public const TIEMPO_PREP_MAXIMO = 240;

    /** @var list<string> */
    public const INTERRUPTORES = ['acepta_delivery', 'acepta_recojo', 'acepta_pedidos_auto', 'sonido_nuevos_pedidos', 'visible_en_app'];

    protected function prepareForValidation(): void
    {
        $datos = [];

        foreach (self::INTERRUPTORES as $campo) {
            $datos[$campo] = $this->boolean($campo);
        }

        foreach (['costo_delivery', 'pedido_minimo'] as $campo) {
            $valor = $this->input($campo);
            $datos[$campo] = is_string($valor) ? str_replace(',', '.', trim($valor)) : $valor;
        }

        $datos['tiempo_prep_min'] = $this->filled('tiempo_prep_min') ? $this->input('tiempo_prep_min') : null;

        $this->merge($datos);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $reglas = [
            'costo_delivery' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::COSTO_DELIVERY_MAXIMO],
            'pedido_minimo' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::PEDIDO_MINIMO_MAXIMO],
            'tiempo_prep_min' => ['nullable', 'integer', 'min:'.self::TIEMPO_PREP_MINIMO, 'max:'.self::TIEMPO_PREP_MAXIMO],
        ];

        foreach (self::INTERRUPTORES as $campo) {
            $reglas[$campo] = ['boolean'];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'costo_delivery.required' => 'Escribe el costo del delivery (0 si es gratis).',
            'costo_delivery.numeric' => 'El costo del delivery debe ser un monto, por ejemplo 5.00.',
            'costo_delivery.decimal' => 'El costo del delivery admite hasta 2 decimales.',
            'costo_delivery.min' => 'El costo del delivery no puede ser negativo.',
            'costo_delivery.max' => 'El costo del delivery admite hasta S/ '.number_format(self::COSTO_DELIVERY_MAXIMO, 2).'.',
            'pedido_minimo.required' => 'Escribe el pedido mínimo (0 si no tienes).',
            'pedido_minimo.numeric' => 'El pedido mínimo debe ser un monto, por ejemplo 20.00.',
            'pedido_minimo.decimal' => 'El pedido mínimo admite hasta 2 decimales.',
            'pedido_minimo.min' => 'El pedido mínimo no puede ser negativo.',
            'pedido_minimo.max' => 'El pedido mínimo admite hasta S/ '.number_format(self::PEDIDO_MINIMO_MAXIMO, 2).'.',
            'tiempo_prep_min.integer' => 'El tiempo de preparación debe ser un número entero de minutos.',
            'tiempo_prep_min.min' => 'El tiempo de preparación debe ser de al menos '.self::TIEMPO_PREP_MINIMO.' minutos.',
            'tiempo_prep_min.max' => 'El tiempo de preparación admite hasta '.self::TIEMPO_PREP_MAXIMO.' minutos.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('acepta_delivery') && ! $this->boolean('acepta_recojo')) {
                    $validator->errors()->add('acepta_delivery', 'Activa al menos delivery o recojo en tienda: si no, nadie podría pedirte.');
                }
            },
        ];
    }
}
