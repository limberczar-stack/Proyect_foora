<?php

namespace App\Http\Requests\Configuracion;

use Illuminate\Contracts\Validation\Validator;

/**
 * Métodos de pago del local. Yape y Plin activos necesitan el celular (9 dígitos) y el titular,
 * porque el cliente paga ahí y el local valida el pago a mano. Debe quedar al menos un método activo.
 */
class PagoRequest extends ConfiguracionRequest
{
    /** @var list<string> */
    public const CODIGOS = ['efectivo', 'yape', 'plin', 'tarjeta'];

    /** @var list<string> */
    public const CON_CUENTA = ['yape', 'plin'];

    protected function prepareForValidation(): void
    {
        $metodos = $this->input('metodos');

        if (! is_array($metodos)) {
            return;
        }

        foreach (self::CODIGOS as $codigo) {
            $fila = is_array($metodos[$codigo] ?? null) ? $metodos[$codigo] : [];
            $metodos[$codigo] = ['activo' => filter_var($fila['activo'] ?? false, FILTER_VALIDATE_BOOLEAN)];

            if (in_array($codigo, self::CON_CUENTA, true)) {
                $numero = $this->celularNormalizado($fila['numero_cuenta'] ?? null);
                $titular = is_string($fila['titular'] ?? null) ? trim($fila['titular']) : ($fila['titular'] ?? null);
                $metodos[$codigo]['numero_cuenta'] = $numero === '' ? null : $numero;
                $metodos[$codigo]['titular'] = $titular === '' ? null : $titular;
            }
        }

        $this->merge(['metodos' => $metodos]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $reglas = ['metodos' => ['required', 'array']];

        foreach (self::CODIGOS as $codigo) {
            $reglas["metodos.{$codigo}.activo"] = ['boolean'];
        }

        foreach (self::CON_CUENTA as $codigo) {
            $reglas["metodos.{$codigo}.numero_cuenta"] = ["required_if_accepted:metodos.{$codigo}.activo", 'nullable', 'string', 'regex:/\A9[0-9]{8}\z/'];
            $reglas["metodos.{$codigo}.titular"] = ["required_if_accepted:metodos.{$codigo}.activo", 'nullable', 'string', 'max:120'];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $mensajes = ['metodos.required' => 'Elige al menos un método de pago.'];

        foreach (['yape' => 'Yape', 'plin' => 'Plin'] as $codigo => $nombre) {
            $mensajes["metodos.{$codigo}.numero_cuenta.required_if_accepted"] = "Para activar {$nombre}, escribe el celular donde te pagan.";
            $mensajes["metodos.{$codigo}.numero_cuenta.regex"] = "El celular de {$nombre} debe tener 9 dígitos y empezar con 9.";
            $mensajes["metodos.{$codigo}.numero_cuenta.string"] = "El celular de {$nombre} debe tener 9 dígitos y empezar con 9.";
            $mensajes["metodos.{$codigo}.titular.required_if_accepted"] = "Para activar {$nombre}, escribe el nombre del titular.";
            $mensajes["metodos.{$codigo}.titular.string"] = 'Escribe el nombre del titular.';
            $mensajes["metodos.{$codigo}.titular.max"] = 'El titular admite hasta 120 caracteres.';
        }

        return $mensajes;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $metodos = $this->input('metodos');

                if (! is_array($metodos)) {
                    return;
                }

                $activos = array_filter(self::CODIGOS, fn (string $codigo): bool => (bool) ($metodos[$codigo]['activo'] ?? false));

                if ($activos === []) {
                    $validator->errors()->add('metodos', 'Deja al menos un método de pago activo: sin él, tus clientes no podrían pagar.');
                }
            },
        ];
    }
}
