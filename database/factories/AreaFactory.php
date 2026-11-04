<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Restaurante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Area>
 */
class AreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurante_id' => Restaurante::factory(),
            'nombre' => fake()->unique()->randomElement(['Salón principal', 'Terraza', 'Patio', 'Segundo piso', 'Barra', 'Jardín']),
            'activa' => true,
            'orden' => 0,
        ];
    }
}
