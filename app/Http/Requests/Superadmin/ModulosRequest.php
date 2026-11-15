<?php

namespace App\Http\Requests\Superadmin;

use App\Support\Modulos;

/**
 * Módulos encendidos en un local: un booleano por cada módulo que hoy se puede encender
 * (Modulos::encendiblesPorLocal()). Las claves desconocidas no se guardan.
 */
class ModulosRequest extends SuperadminRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $reglas = ['modulos' => ['required', 'array']];

        foreach (array_keys(Modulos::encendiblesPorLocal()) as $modulo) {
            $reglas["modulos.{$modulo}"] = ['required', 'boolean'];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'modulos.required' => 'Indica qué módulos quedan encendidos.',
            'modulos.*.required' => 'Indica si el módulo queda encendido.',
            'modulos.*.boolean' => 'Indica si el módulo queda encendido.',
        ];
    }

    /**
     * @return list<string> los módulos que quedan encendidos
     */
    public function encendidos(): array
    {
        return array_values(array_filter(
            array_keys(Modulos::encendiblesPorLocal()),
            fn (string $modulo): bool => $this->boolean("modulos.{$modulo}"),
        ));
    }
}
