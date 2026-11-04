<?php

namespace Database\Factories;

use App\Models\CategoriaProducto;
use App\Models\Restaurante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoriaProducto>
 */
class CategoriaProductoFactory extends Factory
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
            'parent_id' => null,
            'nombre' => fake()->unique()->randomElement(['Entradas', 'Fondos', 'Postres', 'Bebidas', 'Parrillas', 'Sopas', 'Ensaladas', 'Pastas', 'Piqueos', 'Sánguches']),
            'orden' => 0,
            'activa' => true,
            'con_fotos' => true,
        ];
    }

    public function sinFotos(): static
    {
        return $this->state(fn (array $attributes): array => ['con_fotos' => false]);
    }

    /**
     * Subcategoría de la categoría dada (mismo restaurante).
     */
    public function hijaDe(CategoriaProducto $padre): static
    {
        return $this->state(fn (array $attributes): array => [
            'restaurante_id' => $padre->restaurante_id,
            'parent_id' => $padre->id,
        ]);
    }
}
