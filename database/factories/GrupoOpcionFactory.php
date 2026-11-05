<?php

namespace Database\Factories;

use App\Models\GrupoOpcion;
use App\Models\Restaurante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GrupoOpcion>
 */
class GrupoOpcionFactory extends Factory
{
    /**
     * Grupo opcional de elección única.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurante_id' => Restaurante::factory(),
            'nombre' => fake()->unique()->words(2, true),
            'tipo' => 'unica',
            'obligatorio' => false,
            'min_opciones' => 0,
            'max_opciones' => 1,
            'activo' => true,
        ];
    }

    public function obligatorio(): static
    {
        return $this->state(fn (array $attributes): array => ['obligatorio' => true, 'min_opciones' => 1]);
    }

    public function multiple(int $minimo = 0, ?int $maximo = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'tipo' => 'multiple',
            'obligatorio' => $minimo > 0,
            'min_opciones' => $minimo,
            'max_opciones' => $maximo,
        ]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $attributes): array => ['activo' => false]);
    }
}
