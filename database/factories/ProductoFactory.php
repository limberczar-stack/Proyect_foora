<?php

namespace Database\Factories;

use App\Models\CategoriaProducto;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'categoria_id' => CategoriaProducto::factory(),
            'restaurante_id' => fn (array $attributes): int => CategoriaProducto::withoutGlobalScopes()->findOrFail($attributes['categoria_id'])->restaurante_id,
            'tipo' => 'simple',
            'nombre' => fake()->unique()->words(3, true),
            'descripcion' => fake()->sentence(),
            'imagen' => 'productos/ejemplo.jpg',
            'precio' => fake()->randomFloat(2, 5, 60),
            'disponible' => true,
            'destacado' => false,
            'estacion' => 'cocina',
            'etiquetas' => [],
            'orden' => 0,
        ];
    }

    public function sinImagen(): static
    {
        return $this->state(fn (array $attributes): array => ['imagen' => null]);
    }

    public function sinDescripcion(): static
    {
        return $this->state(fn (array $attributes): array => ['descripcion' => null]);
    }
}
