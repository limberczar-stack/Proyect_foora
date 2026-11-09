<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Suscripcion>
 */
class SuscripcionFactory extends Factory
{
    /**
     * Una suscripción activa que vence dentro de 20 días.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurante_id' => Restaurante::factory(),
            'plan_id' => fn (): int => Plan::firstOrCreate(['nombre' => 'Básico'], ['precio_mensual' => 39])->id,
            'estado' => 'activa',
            'inicia_el' => now(Restaurante::ZONA_POR_DEFECTO)->subDays(10)->toDateString(),
            'vence_el' => now(Restaurante::ZONA_POR_DEFECTO)->addDays(20)->toDateString(),
            'precio_pactado' => 39,
        ];
    }

    public function prueba(): static
    {
        return $this->state(fn (array $attributes): array => ['estado' => 'prueba', 'precio_pactado' => 0]);
    }

    public function cancelada(): static
    {
        return $this->state(fn (array $attributes): array => ['estado' => 'cancelada']);
    }

    /**
     * Vence dentro de esa cantidad de días, contados en la zona del local; negativo si ya venció.
     */
    public function venceEn(int $dias): static
    {
        return $this->state(fn (array $attributes): array => ['vence_el' => now(Restaurante::ZONA_POR_DEFECTO)->addDays($dias)->toDateString()]);
    }
}
