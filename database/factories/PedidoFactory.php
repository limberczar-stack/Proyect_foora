<?php

namespace Database\Factories;

use App\Models\Cuenta;
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

    /**
     * Una ronda de una cuenta de salón, ya en preparación. Sin cliente ni método de pago.
     * Para una ronda real (con líneas y totales del servidor) usa CrearPedidoSalon.
     */
    public function salon(Cuenta $cuenta, int $ronda = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'restaurante_id' => $cuenta->restaurante_id,
            'cuenta_id' => $cuenta->id,
            'ronda' => $ronda,
            'nombre_cliente' => null,
            'telefono_cliente' => null,
            'metodo_pago_id' => null,
            'tipo' => 'salon',
            'origen' => 'local',
            'estado' => 'en_preparacion',
            'aceptado_at' => now(),
        ]);
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
