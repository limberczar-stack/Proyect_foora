<?php

namespace App\Http\Requests;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Pedido público. Como el carrito, solo admite ids y cantidades: nunca precios.
 * Exige JSON: sin sesión no hay CSRF, y un formulario de otro sitio no puede enviar ese tipo.
 */
class PedidoRequest extends ValidarCarritoRequest
{
    public const OBSERVACION_MAXIMA = 200;

    /** Campo trampa: es invisible para una persona, y solo un bot lo llena. */
    public const CAMPO_TRAMPA = 'sitio_web';

    protected function prepareForValidation(): void
    {
        abort_unless($this->isJson(), 415);

        if (filled($this->input(self::CAMPO_TRAMPA))) {
            $this->rechazarEnSilencio();
        }

        $this->merge([
            'nombre' => $this->textoLimpio('nombre'),
            'telefono' => $this->telefonoNormalizado(),
            'direccion' => $this->textoLimpio('direccion'),
            'referencia' => $this->textoLimpio('referencia'),
            'referencia_pago' => $this->textoLimpio('referencia_pago'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'lineas' => ['required', 'array', 'max:'.self::LINEAS_MAXIMAS],
            'lineas.*.observacion' => ['nullable', 'string', 'max:'.self::OBSERVACION_MAXIMA],
            'nombre' => ['required', 'string', 'min:2', 'max:120'],
            'telefono' => ['required', 'regex:/\A9[0-9]{8}\z/'],
            'tipo' => ['required', Rule::in(['delivery', 'recojo'])],
            'direccion' => ['required_if:tipo,delivery', 'nullable', 'string', 'max:255'],
            'referencia' => ['required_if:tipo,delivery', 'nullable', 'string', 'max:255'],
            'metodo_pago_id' => ['required', 'integer'],
            'referencia_pago' => ['nullable', 'string', 'max:50'],
            'acepta_privacidad' => ['accepted'],
            'idempotency_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9-]{16,64}\z/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'lineas.required' => 'Tu carrito está vacío.',
            'lineas.*.observacion.max' => 'Las observaciones admiten hasta '.self::OBSERVACION_MAXIMA.' caracteres.',
            'nombre.required' => 'Escribe tu nombre.',
            'nombre.min' => 'Escribe tu nombre.',
            'nombre.max' => 'El nombre admite hasta 120 caracteres.',
            'telefono.required' => 'Escribe tu celular.',
            'telefono.regex' => 'Escribe un celular peruano de 9 dígitos que empiece con 9.',
            'tipo.required' => 'Elige delivery o recojo en local.',
            'tipo.in' => 'Elige delivery o recojo en local.',
            'direccion.required_if' => 'Escribe la dirección de entrega.',
            'direccion.max' => 'La dirección admite hasta 255 caracteres.',
            'referencia.required_if' => 'Escribe una referencia para encontrar la dirección.',
            'referencia.max' => 'La referencia admite hasta 255 caracteres.',
            'metodo_pago_id.required' => 'Elige un método de pago.',
            'metodo_pago_id.integer' => 'Elige un método de pago.',
            'referencia_pago.max' => 'El código de operación admite hasta 50 caracteres.',
            'acepta_privacidad.accepted' => 'Necesitamos tu consentimiento para enviar el pedido al local.',
            'idempotency_key.required' => 'No pudimos enviar tu pedido. Recarga la página.',
            'idempotency_key.regex' => 'No pudimos enviar tu pedido. Recarga la página.',
        ];
    }

    /**
     * Agrega a cada línea su observación, que no interviene en el cálculo del precio.
     *
     * @return list<array{producto_id: int, variante_id: ?int, opciones: list<int>, cantidad: int, observacion: ?string}>
     */
    public function lineas(): array
    {
        $originales = array_values($this->validated('lineas'));

        return array_map(fn (array $linea, array $original): array => [
            ...$linea,
            'observacion' => filled($original['observacion'] ?? null) ? trim($original['observacion']) : null,
        ], parent::lineas(), $originales);
    }

    /**
     * Responde igual que un pedido correcto, para que el bot no aprenda nada.
     */
    private function rechazarEnSilencio(): never
    {
        $slug = (string) $this->route('slug');
        $codigo = Str::random(32);

        throw new HttpResponseException(response()->json([
            'codigo' => $codigo,
            'url' => route('vitrina.pedido.seguimiento', [$slug, $codigo]),
        ], 201));
    }

    private function textoLimpio(string $campo): ?string
    {
        $valor = $this->input($campo);

        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    /**
     * Acepta +51, espacios y guiones: guarda solo los 9 dígitos.
     */
    private function telefonoNormalizado(): mixed
    {
        $valor = $this->input('telefono');

        if (! is_string($valor) && ! is_int($valor)) {
            return $valor;
        }

        $digitos = preg_replace('/[^0-9]/', '', (string) $valor);

        return strlen($digitos) === 11 && str_starts_with($digitos, '51') ? substr($digitos, 2) : $digitos;
    }
}
