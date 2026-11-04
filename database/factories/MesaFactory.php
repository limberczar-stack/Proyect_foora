<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Mesa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mesa>
 */
class MesaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Para que mesa y área sean del mismo restaurante, usa ->for($area) o pasa ambos ids.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'area_id' => Area::factory(),
            'restaurante_id' => fn (array $attributes): int => Area::withoutGlobalScopes()->findOrFail($attributes['area_id'])->restaurante_id,
            'codigo' => 'M'.fake()->unique()->numberBetween(1, 999),
            'capacidad' => fake()->randomElement([2, 4, 6]),
            'estado' => 'disponible',
            'activa' => true,
        ];
    }
}
