<?php

namespace Tests\Feature\Configuracion;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PedidosYNotificacionesConfigTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->create();
        $this->admin = User::factory()->admin($this->restaurante)->create();
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function pedidos(array $cambios = []): array
    {
        return [
            'acepta_delivery' => '1',
            'acepta_recojo' => '1',
            'costo_delivery' => '5.50',
            'pedido_minimo' => '20',
            'tiempo_prep_min' => '35',
            'acepta_pedidos_auto' => '1',
            'sonido_nuevos_pedidos' => '0',
            'visible_en_app' => '1',
            ...$cambios,
        ];
    }

    public function test_admin_saves_how_orders_are_received(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.pedidos.update'), $this->pedidos(['costo_delivery' => '4,90']))
            ->assertOk();

        $restaurante = $this->restaurante->fresh();
        $this->assertSame('4.90', $restaurante->costo_delivery);
        $this->assertSame('20.00', $restaurante->pedido_minimo);
        $this->assertSame(35, $restaurante->tiempo_prep_min);
        $this->assertTrue($restaurante->acepta_pedidos_auto);
        $this->assertFalse($restaurante->sonido_nuevos_pedidos);
    }

    public function test_the_page_explains_that_yape_and_plin_always_arrive_as_new(): void
    {
        $this->actingAs($this->admin)
            ->get(route('panel.configuracion.pedidos'))
            ->assertOk()
            ->assertSee('Los pedidos pagados con Yape o Plin siempre entran como «Nuevo»');
    }

    public function test_at_least_delivery_or_pickup_must_be_active(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.pedidos.update'), $this->pedidos(['acepta_delivery' => '0', 'acepta_recojo' => '0']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('acepta_delivery');
    }

    public function test_amounts_have_two_decimals_no_negatives_and_a_ceiling(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.pedidos.update'), $this->pedidos([
                'costo_delivery' => '5.555',
                'pedido_minimo' => '-1',
                'tiempo_prep_min' => '2',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['costo_delivery', 'pedido_minimo', 'tiempo_prep_min']);

        $this->putJson(route('panel.configuracion.pedidos.update'), $this->pedidos([
            'costo_delivery' => '100',
            'pedido_minimo' => '1000',
            'tiempo_prep_min' => '241',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['costo_delivery', 'pedido_minimo', 'tiempo_prep_min']);

        // Vacío = sin tiempo estimado.
        $this->putJson(route('panel.configuracion.pedidos.update'), $this->pedidos(['tiempo_prep_min' => '']))->assertOk();
        $this->assertNull($this->restaurante->fresh()->tiempo_prep_min);
    }

    public function test_notifications_use_defaults_when_empty_and_save_the_three_keys(): void
    {
        $this->assertNull($this->restaurante->config_notificaciones);
        $this->assertSame(['nuevos_pedidos' => true, 'reservas' => true, 'alertas' => true], $this->restaurante->notificaciones());

        $this->restaurante->forceFill(['config_notificaciones' => ['reservas' => false]])->save();
        $this->assertSame(['nuevos_pedidos' => true, 'reservas' => false, 'alertas' => true], $this->restaurante->fresh()->notificaciones());

        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.notificaciones.update'), ['nuevos_pedidos' => '1', 'reservas' => '0', 'alertas' => '0', 'extra' => '1'])
            ->assertOk();

        $this->assertSame(['nuevos_pedidos' => true, 'reservas' => false, 'alertas' => false], $this->restaurante->fresh()->config_notificaciones);
    }
}
