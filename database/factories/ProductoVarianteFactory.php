<?php

namespace Database\Factories;

use App\Models\Producto;
use App\Models\ProductoVariante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductoVariante>
 */
class ProductoVarianteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'producto_id' => Producto::factory()->state(['tipo' => 'variantes']),
            'nombre' => fake()->unique()->words(2, true),
            'precio' => fake()->randomFloat(2, 5, 60),
            'disponible' => true,
            'orden' => 0,
        ];
    }

    public function agotada(): static
    {
        return $this->state(fn (array $attributes): array => ['disponible' => false]);
    }
}
