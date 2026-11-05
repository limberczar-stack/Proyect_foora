<?php

namespace Database\Factories;

use App\Models\GrupoOpcion;
use App\Models\Opcion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opcion>
 */
class OpcionFactory extends Factory
{
    /**
     * Opción sin costo extra.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'grupo_id' => GrupoOpcion::factory(),
            'nombre' => fake()->unique()->words(2, true),
            'precio_extra' => 0,
            'disponible' => true,
            'orden' => 0,
        ];
    }

    public function agotada(): static
    {
        return $this->state(fn (array $attributes): array => ['disponible' => false]);
    }
}
