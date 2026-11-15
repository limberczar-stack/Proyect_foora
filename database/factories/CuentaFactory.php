<?php

namespace Database\Factories;

use App\Models\Cuenta;
use App\Models\Restaurante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Una cuenta suelta, sin mesas. Para una cuenta real (con sus mesas ocupadas) usa GestionarCuentas::abrir().
 *
 * @extends Factory<Cuenta>
 */
class CuentaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurante_id' => Restaurante::factory(),
            'numero' => fake()->unique()->numberBetween(1, 999999),
            'estado' => 'abierta',
            'version' => 1,
            'comensales' => 2,
            'abierta_at' => now(),
        ];
    }

    public function cerrada(): static
    {
        return $this->state(fn (array $attributes): array => ['estado' => 'cerrada', 'cierre_tipo' => 'externo', 'cerrada_at' => now()]);
    }
}
