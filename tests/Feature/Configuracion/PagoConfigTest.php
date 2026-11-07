<?php

namespace Tests\Feature\Configuracion;

use App\Models\MetodoPago;
use App\Models\Restaurante;
use App\Models\RestauranteMetodoPago;
use App\Models\User;
use Database\Seeders\MetodoPagoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagoConfigTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MetodoPagoSeeder::class);
        $this->restaurante = Restaurante::factory()->create();
        $this->admin = User::factory()->admin($this->restaurante)->create();
    }

    /**
     * @param  array<string, array<string, mixed>>  $cambios
     * @return array{metodos: array<string, array<string, mixed>>}
     */
    private function metodos(array $cambios = []): array
    {
        return ['metodos' => [
            'efectivo' => ['activo' => '1'],
            'yape' => ['activo' => '0', 'numero_cuenta' => '', 'titular' => ''],
            'plin' => ['activo' => '0', 'numero_cuenta' => '', 'titular' => ''],
            'tarjeta' => ['activo' => '0'],
            ...$cambios,
        ]];
    }

    private function guardado(string $codigo): ?RestauranteMetodoPago
    {
        return RestauranteMetodoPago::where('restaurante_id', $this->restaurante->id)
            ->where('metodo_pago_id', MetodoPago::where('codigo', $codigo)->value('id'))
            ->first();
    }

    public function test_admin_activates_yape_with_number_and_holder(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.pago.update'), $this->metodos([
                'yape' => ['activo' => '1', 'numero_cuenta' => '+51 987 654 321', 'titular' => '  María Rojas '],
                'tarjeta' => ['activo' => '1'],
            ]))
            ->assertOk();

        $yape = $this->guardado('yape');
        $this->assertTrue($yape->activo);
        $this->assertSame('987654321', $yape->numero_cuenta);
        $this->assertSame('María Rojas', $yape->titular);
        $this->assertTrue($this->guardado('tarjeta')->activo);
        $this->assertFalse($this->guardado('plin')->activo);

        $this->assertSame(['efectivo', 'yape', 'tarjeta'], $this->restaurante->fresh()->load('metodosPago')->metodosPagoDisponibles()->pluck('codigo')->all());
    }

    public function test_yape_and_plin_cannot_be_activated_without_number_and_holder(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.pago.update'), $this->metodos([
                'yape' => ['activo' => '1', 'numero_cuenta' => '', 'titular' => ''],
                'plin' => ['activo' => '1', 'numero_cuenta' => '98765432', 'titular' => 'Juan'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['metodos.yape.numero_cuenta', 'metodos.yape.titular', 'metodos.plin.numero_cuenta'])
            ->assertJsonMissingValidationErrors('metodos.plin.titular')
            ->assertJsonValidationErrors([
                'metodos.yape.numero_cuenta' => 'Para activar Yape, escribe el celular donde te pagan.',
                'metodos.plin.numero_cuenta' => 'El celular de Plin debe tener 9 dígitos y empezar con 9.',
            ]);

        $this->assertNull($this->guardado('yape'));
    }

    public function test_at_least_one_method_must_stay_active(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.pago.update'), $this->metodos(['efectivo' => ['activo' => '0']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metodos');
    }

    public function test_an_inactive_yape_keeps_its_data_but_is_not_offered(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.pago.update'), $this->metodos([
                'yape' => ['activo' => '0', 'numero_cuenta' => '912345678', 'titular' => 'María'],
            ]))
            ->assertOk();

        $this->assertSame('912345678', $this->guardado('yape')->numero_cuenta);
        $this->assertSame(['efectivo'], $this->restaurante->fresh()->load('metodosPago')->metodosPagoDisponibles()->pluck('codigo')->all());
    }
}
