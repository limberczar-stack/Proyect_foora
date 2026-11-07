<?php

namespace App\Http\Requests\Configuracion;

use Illuminate\Contracts\Validation\Validator;

/**
 * Horario de los 7 días (1 = lunes ... 7 = domingo). Un día abierto necesita apertura y cierre.
 * Si el cierre es menor que la apertura, el horario termina al día siguiente (por ejemplo de 18:00 a 02:00),
 * tal como lo entienden HorarioAtencion y Restaurante::abiertoAhora().
 */
class HorarioRequest extends ConfiguracionRequest
{
    /** @var array<int, string> */
    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    protected function prepareForValidation(): void
    {
        $dias = $this->input('dias');

        if (! is_array($dias)) {
            return;
        }

        foreach (array_keys(self::DIAS) as $dia) {
            if (! array_key_exists($dia, $dias)) {
                continue;
            }

            $fila = is_array($dias[$dia]) ? $dias[$dia] : [];
            $cerrado = filter_var($fila['cerrado'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $dias[$dia] = [
                'cerrado' => $cerrado,
                'abre' => $cerrado ? null : $this->hora($fila['abre'] ?? null),
                'cierra' => $cerrado ? null : $this->hora($fila['cierra'] ?? null),
            ];
        }

        $this->merge(['dias' => $dias]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $reglas = ['dias' => ['required', 'array']];

        foreach (array_keys(self::DIAS) as $dia) {
            $reglas["dias.{$dia}"] = ['required', 'array'];
            $reglas["dias.{$dia}.cerrado"] = ['boolean'];
            $reglas["dias.{$dia}.abre"] = ["exclude_if:dias.{$dia}.cerrado,true", 'required', 'date_format:H:i'];
            $reglas["dias.{$dia}.cierra"] = ["exclude_if:dias.{$dia}.cerrado,true", 'required', 'date_format:H:i', "different:dias.{$dia}.abre"];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $mensajes = ['dias.required' => 'Completa el horario de la semana.'];

        foreach (self::DIAS as $dia => $nombre) {
            $mensajes["dias.{$dia}.required"] = "Falta el horario del {$nombre}.";
            $mensajes["dias.{$dia}.abre.required"] = "{$nombre}: indica la hora de apertura o márcalo como cerrado.";
            $mensajes["dias.{$dia}.abre.date_format"] = "{$nombre}: la apertura debe ser una hora válida, por ejemplo 12:00.";
            $mensajes["dias.{$dia}.cierra.required"] = "{$nombre}: indica la hora de cierre o márcalo como cerrado.";
            $mensajes["dias.{$dia}.cierra.date_format"] = "{$nombre}: el cierre debe ser una hora válida, por ejemplo 22:00.";
            $mensajes["dias.{$dia}.cierra.different"] = "{$nombre}: la apertura y el cierre no pueden ser la misma hora.";
        }

        return $mensajes;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $dias = $this->input('dias');

                if (is_array($dias) && array_diff(array_keys($dias), array_keys(self::DIAS)) !== []) {
                    $validator->errors()->add('dias', 'Solo se aceptan los días de lunes a domingo.');
                }
            },
        ];
    }

    /**
     * Normaliza 9:00 o 09:00:00 a 09:00. Lo que no parece una hora llega tal cual para que la validación lo rechace.
     */
    private function hora(mixed $valor): mixed
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        $valor = trim($valor);

        if (preg_match('/\A(\d{1,2}):(\d{2})(?::\d{2})?\z/', $valor, $partes)) {
            return str_pad($partes[1], 2, '0', STR_PAD_LEFT).':'.$partes[2];
        }

        return $valor;
    }
}
