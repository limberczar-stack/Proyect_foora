<?php

namespace Database\Factories;

use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Restaurante;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Pedido>
 */
class PedidoFactory extends Factory
{
    private static int $numero = 0;

    /**
     * Pedido de recojo recién recibido.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurante_id' => Restaurante::factory(),
            'numero' => ++self::$numero,
            'codigo_seguimiento' => Str::random(32),
            'nombre_cliente' => 'Ana Torres',
            'telefono_cliente' => fake()->numerify('9########'),
            'tipo' => 'recojo',
            'metodo_pago_id' => fn (): int => MetodoPago::firstOrCreate(['codigo' => 'efectivo'], ['nombre' => 'Efectivo'])->id,
            'estado_pago' => 'pendiente',
            'subtotal' => 30,
            'total' => 30,
            'estado' => 'nuevo',
        ];
    }

    public function delivery(): static
    {
        return $this->state(fn (array $attributes): array => [
            'tipo' => 'delivery',
            'direccion_entrega' => 'Jr. Huallayco 840',
            'referencia_entrega' => 'Casa azul con puerta negra',
        ]);
    }
}
