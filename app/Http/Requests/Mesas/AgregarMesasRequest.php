<?php

namespace App\Http\Requests\Mesas;

use App\Models\Mesa;
use App\Services\GestionarMesas;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Agregar varias": del número desde al hasta, con un prefijo opcional (T → T1, T2…).
 * El código más largo del rango (prefijo + hasta) debe caber en mesas.codigo.
 */
class AgregarMesasRequest extends FormRequest
{
    public const NUMERO_MAXIMO = 999;

    public function authorize(): bool
    {
        return $this->user()->can('gestionar', Mesa::class);
    }

    protected function prepareForValidation(): void
    {
        $prefijo = $this->input('prefijo');

        $this->merge([
            'prefijo' => is_string($prefijo) && trim($prefijo) !== '' ? preg_replace('/\s+/u', ' ', ltrim($prefijo)) : null,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'area_id' => ['required', 'integer', Rule::exists('areas', 'id')->where('restaurante_id', $this->user()->restaurante_id)],
            'prefijo' => ['nullable', 'string', 'max:'.(Mesa::CODIGO_MAXIMO - 1), 'regex:'.MesaRequest::PATRON_CODIGO],
            'desde' => ['required', 'integer', 'between:1,'.self::NUMERO_MAXIMO],
            'hasta' => ['required', 'integer', 'between:1,'.self::NUMERO_MAXIMO, 'gte:desde'],
            'capacidad' => ['required', 'integer', 'between:'.Mesa::CAPACIDAD_MINIMA.','.Mesa::CAPACIDAD_MAXIMA],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['prefijo', 'desde', 'hasta'])) {
                    return;
                }

                $desde = (int) $this->input('desde');
                $hasta = (int) $this->input('hasta');
                $prefijo = (string) $this->input('prefijo');

                if ($hasta - $desde + 1 > GestionarMesas::MAXIMO_POR_LOTE) {
                    $validator->errors()->add('hasta', 'Puedes agregar hasta '.GestionarMesas::MAXIMO_POR_LOTE.' mesas a la vez.');
                }

                $masLargo = $prefijo.$hasta;

                if (mb_strlen($masLargo) > Mesa::CODIGO_MAXIMO) {
                    $validator->errors()->add('prefijo', "Con ese prefijo, «{$masLargo}» pasa de ".Mesa::CODIGO_MAXIMO.' caracteres. Acórtalo.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...MesaRequest::mensajes(),
            'prefijo.max' => 'El prefijo es demasiado largo.',
            'prefijo.regex' => 'El prefijo admite solo letras, números, espacios y guiones.',
            'desde.required' => 'Indica desde qué número empiezan.',
            'desde.integer' => 'Usa números enteros.',
            'desde.between' => 'Los números van de 1 a '.self::NUMERO_MAXIMO.'.',
            'hasta.required' => 'Indica hasta qué número llegan.',
            'hasta.integer' => 'Usa números enteros.',
            'hasta.between' => 'Los números van de 1 a '.self::NUMERO_MAXIMO.'.',
            'hasta.gte' => '«Hasta» debe ser igual o mayor que «desde».',
        ];
    }
}
