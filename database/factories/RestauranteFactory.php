<?php

namespace Database\Factories;

use App\Models\Restaurante;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurante>
 */
class RestauranteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = 'Restaurante '.fake()->unique()->lastName();

        return [
            'nombre' => $nombre,
            'slug' => Str::slug($nombre).'-'.Str::lower(Str::random(4)),
            'ciudad' => 'Huánuco',
            'telefono' => fake()->numerify('9########'),
            'estado_local' => 'abierto',
            'activo' => true,
        ];
    }

    public function cerrado(): static
    {
        return $this->state(fn (array $attributes): array => ['estado_local' => 'cerrado']);
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $attributes): array => ['activo' => false]);
    }
}
