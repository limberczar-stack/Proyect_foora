<?php

namespace Database\Factories;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state: un cliente final, sin restaurante.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'rol' => 'cliente',
            'activo' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Personal del restaurante con el rol dado (crea un restaurante si no se indica uno).
     */
    public function personal(string $rol, ?Restaurante $restaurante = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'rol' => $rol,
            'restaurante_id' => $restaurante?->id ?? Restaurante::factory(),
        ]);
    }

    public function admin(?Restaurante $restaurante = null): static
    {
        return $this->personal('admin', $restaurante);
    }

    public function cajero(?Restaurante $restaurante = null): static
    {
        return $this->personal('cajero', $restaurante);
    }

    public function cliente(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rol' => 'cliente',
            'restaurante_id' => null,
        ]);
    }

    public function superadmin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rol' => 'superadmin',
            'restaurante_id' => null,
        ]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $attributes): array => ['activo' => false]);
    }
}
