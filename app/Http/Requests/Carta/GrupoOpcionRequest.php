<?php

namespace App\Http\Requests\Carta;

use App\Models\GrupoOpcion;
use App\Models\Producto;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear o editar un grupo de opciones y sus opciones.
 *
 * Tipo única solo admite una elección: el máximo es 1 y el mínimo sale de "obligatorio".
 * En tipo múltiple, obligatorio y mínimo deben ser coherentes en ambas direcciones.
 */
class GrupoOpcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $grupo = $this->grupo();

        return $grupo
            ? $this->user()->can('update', $grupo)
            : $this->user()->can('create', GrupoOpcion::class);
    }

    protected function prepareForValidation(): void
    {
        $opciones = collect($this->input('opciones', []))
            ->filter(fn ($fila): bool => is_array($fila) && (filled($fila['nombre'] ?? null) || filled($fila['precio_extra'] ?? null)))
            ->map(fn (array $fila): array => [
                'id' => filled($fila['id'] ?? null) ? (int) $fila['id'] : null,
                'nombre' => trim((string) ($fila['nombre'] ?? '')),
                'precio_extra' => $this->normalizarPrecio($fila['precio_extra'] ?? null),
                'disponible' => filter_var($fila['disponible'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->values()
            ->all();

        $obligatorio = $this->boolean('obligatorio');
        $esUnica = $this->input('tipo') === 'unica';

        $this->merge([
            'obligatorio' => $obligatorio,
            'activo' => $this->boolean('activo'),
            'min_opciones' => $esUnica ? (int) $obligatorio : ($this->filled('min_opciones') ? $this->input('min_opciones') : 0),
            'max_opciones' => $esUnica ? 1 : ($this->filled('max_opciones') ? $this->input('max_opciones') : null),
            'opciones' => $opciones,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:80'],
            'tipo' => ['required', Rule::in(array_keys(GrupoOpcion::TIPOS))],
            'obligatorio' => ['boolean'],
            'min_opciones' => ['required', 'integer', 'min:0', 'max:'.GrupoOpcion::LIMITE_OPCIONES],
            'max_opciones' => ['nullable', 'integer', 'min:1', 'max:'.GrupoOpcion::LIMITE_OPCIONES],
            'activo' => ['boolean'],
            'opciones' => ['required', 'array', 'min:1'],
            'opciones.*.id' => ['nullable', 'integer'],
            'opciones.*.nombre' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
            'opciones.*.precio_extra' => ['required', 'decimal:0,2', 'min:0', 'max:'.Producto::PRECIO_MAXIMO],
            'opciones.*.disponible' => ['boolean'],
        ];
    }

    /**
     * Reglas entre campos: solo corren si cada campo ya es válido por sí solo.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['tipo', 'min_opciones', 'max_opciones', 'opciones'])) {
                    return;
                }

                $minimo = (int) $this->input('min_opciones');
                $maximo = $this->filled('max_opciones') ? (int) $this->input('max_opciones') : null;
                $obligatorio = $this->boolean('obligatorio');
                $totalOpciones = count($this->input('opciones'));

                if ($minimo > 0 && ! $obligatorio) {
                    $validator->errors()->add('min_opciones', 'Si el mínimo es mayor que 0, el grupo debe ser obligatorio.');
                }

                if ($obligatorio && $minimo < 1) {
                    $validator->errors()->add('min_opciones', 'Un grupo obligatorio necesita un mínimo de al menos 1.');
                }

                if ($maximo !== null && $maximo < $minimo) {
                    $validator->errors()->add('max_opciones', 'El máximo no puede ser menor que el mínimo.');
                }

                if ($minimo > $totalOpciones) {
                    $validator->errors()->add('min_opciones', "El mínimo no puede superar las opciones del grupo ({$totalOpciones}).");
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maximoPrecio = number_format(Producto::PRECIO_MAXIMO, 2);
        $limite = GrupoOpcion::LIMITE_OPCIONES;

        return [
            'nombre.required' => 'Escribe el nombre del grupo.',
            'nombre.max' => 'El nombre admite hasta 80 caracteres.',
            'tipo.required' => 'Elige si es de elección única o múltiple.',
            'tipo.in' => 'Elige si es de elección única o múltiple.',
            'min_opciones.required' => 'Escribe el mínimo de opciones.',
            'min_opciones.integer' => 'El mínimo debe ser un número entero.',
            'min_opciones.min' => 'El mínimo no puede ser negativo.',
            'min_opciones.max' => "El mínimo admite hasta {$limite}.",
            'max_opciones.integer' => 'El máximo debe ser un número entero.',
            'max_opciones.min' => 'El máximo debe ser al menos 1. Déjalo vacío si no tiene límite.',
            'max_opciones.max' => "El máximo admite hasta {$limite}.",
            'opciones.required' => 'Agrega al menos una opción.',
            'opciones.min' => 'Agrega al menos una opción.',
            'opciones.*.nombre.required' => 'Cada opción necesita un nombre.',
            'opciones.*.nombre.max' => 'El nombre de la opción admite hasta 80 caracteres.',
            'opciones.*.nombre.distinct' => 'Hay dos opciones con el mismo nombre.',
            'opciones.*.precio_extra.required' => 'Cada opción necesita un precio extra (puede ser 0).',
            'opciones.*.precio_extra.decimal' => 'El precio admite hasta 2 decimales.',
            'opciones.*.precio_extra.min' => 'El precio extra no puede ser negativo.',
            'opciones.*.precio_extra.max' => "El precio máximo es S/ {$maximoPrecio}.",
        ];
    }

    /**
     * Acepta "3,50" o "3.50". Vacío equivale a sin costo.
     */
    private function normalizarPrecio(mixed $valor): mixed
    {
        if (! is_string($valor) && $valor !== null) {
            return $valor;
        }

        $valor = trim(str_replace(',', '.', (string) $valor));

        return $valor === '' ? '0' : $valor;
    }

    private function grupo(): ?GrupoOpcion
    {
        $grupo = $this->route('grupo');

        return $grupo instanceof GrupoOpcion ? $grupo : null;
    }
}
