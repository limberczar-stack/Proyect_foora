<?php

namespace Tests\Feature\Panel;

use App\Models\Area;
use App\Models\Cuenta;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Restaurante;
use App\Models\User;
use App\Services\GestionarCuentas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un pedido de salón no tiene cliente, teléfono ni método de pago. Las pantallas que antes daban
 * por hecho esos datos deben mostrarlo sin errores, y la cocina nunca recibe montos de ningún pedido.
 */
class PedidoSalonEnPanelTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    private User $cajero;

    private User $cocina;

    private User $mesero;

    private Cuenta $cuenta;

    private Pedido $ronda;

    private Pedido $publico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->conModulos('mesas')->create();
        $this->admin = User::factory()->admin($this->restaurante)->create();
        $this->cajero = User::factory()->cajero($this->restaurante)->create();
        $this->cocina = User::factory()->personal('cocina', $this->restaurante)->create();
        $this->mesero = User::factory()->personal('mesero', $this->restaurante)->create(['name' => 'Luis Chávez']);

        $area = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Salón']);
        $mesas = collect(['4', '5'])->map(fn (string $codigo): Mesa => Mesa::factory()->for($area)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => $codigo]));
        $this->cuenta = app(GestionarCuentas::class)->abrir($this->mesero, $mesas->pluck('id')->all(), 4);

        $this->ronda = Pedido::factory()->salon($this->cuenta, 2)->create(['numero' => 41, 'subtotal' => 73.50, 'total' => 73.50, 'creado_por' => $this->mesero->id]);
        PedidoItem::create(['pedido_id' => $this->ronda->id, 'nombre' => 'Lomo saltado', 'cantidad' => 2, 'precio_unitario' => 36.75, 'subtotal' => 73.50]);

        $this->publico = Pedido::factory()->create(['restaurante_id' => $this->restaurante->id, 'numero' => 40, 'estado' => 'en_preparacion', 'subtotal' => 58.40, 'total' => 58.40]);
        PedidoItem::create(['pedido_id' => $this->publico->id, 'nombre' => 'Pollo a la brasa', 'cantidad' => 1, 'precio_unitario' => 58.40, 'subtotal' => 58.40]);
    }

    public function test_the_salon_order_really_has_no_customer_phone_or_payment_method(): void
    {
        $this->assertNull($this->ronda->fresh()->nombre_cliente);
        $this->assertNull($this->ronda->fresh()->telefono_cliente);
        $this->assertNull($this->ronda->fresh()->metodo_pago_id);
    }

    public function test_it_shows_in_the_order_list_with_its_table_round_and_waiter(): void
    {
        foreach ([$this->admin, $this->cajero] as $usuario) {
            $this->actingAs($usuario)->get(route('panel.pedidos.index', ['estado' => 'en_preparacion']))
                ->assertOk()
                ->assertSee('#41')
                ->assertSee('Mesa 4 + 5 · Ronda 2 · Luis')
                ->assertSee('Por cuenta')
                ->assertSee('Salón');
        }
    }

    public function test_the_salon_filter_shows_only_salon_orders(): void
    {
        $this->actingAs($this->cajero)->get(route('panel.pedidos.index', ['estado' => 'en_preparacion', 'tipo' => 'salon']))
            ->assertOk()->assertSee('#41')->assertDontSee('#40');

        $this->get(route('panel.pedidos.index', ['estado' => 'en_preparacion', 'tipo' => 'recojo']))
            ->assertOk()->assertSee('#40')->assertDontSee('#41');
    }

    public function test_the_salon_filter_is_only_offered_with_the_module_on(): void
    {
        $this->actingAs($this->cajero)->get(route('panel.pedidos.index'))->assertSee('tipo=salon', false)->assertSee('>Salón</a>', false);

        $this->restaurante->forceFill(['modulos' => null])->save();
        $this->actingAs($this->cajero->fresh())->get(route('panel.pedidos.index'))->assertDontSee('tipo=salon', false);
    }

    public function test_its_detail_shows_without_contact_or_payment_buttons(): void
    {
        $this->actingAs($this->cajero)->get(route('panel.pedidos.show', $this->ronda), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Pedido #41')
            ->assertSee('Mesa 4 + 5 · Ronda 2 · Luis')
            ->assertSee('Cuenta #'.$this->cuenta->numero)
            ->assertSee('Lomo saltado')
            ->assertSee('73.50')
            ->assertDontSee('Llamar')
            ->assertDontSee('WhatsApp')
            ->assertDontSee('Notificar al cliente')
            ->assertDontSee('Validar pago')
            ->assertDontSee('Sin método')
            ->assertDontSee('name="cobrado"', false);
    }

    public function test_a_ready_salon_order_is_served_not_delivered(): void
    {
        $this->ronda->update(['estado' => 'listo', 'listo_at' => now()]);

        $this->actingAs($this->cajero)->get(route('panel.pedidos.show', $this->ronda), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('Servido')->assertDontSee('Marcar entregado')->assertDontSee('Cobrado (');

        $this->patchJson(route('panel.pedidos.estado', $this->ronda), ['desde' => 'listo', 'destino' => 'entregado', 'cobrado' => 1])
            ->assertOk()->assertJsonPath('mensaje', 'Pedido #41: servido.');

        $ronda = $this->ronda->fresh();
        $this->assertSame(['entregado', 'pendiente'], [$ronda->estado, $ronda->estado_pago], 'Servir no cobra: el pago es de la cuenta.');
        $this->assertNotNull($ronda->entregado_at);
    }

    public function test_it_shows_on_the_home_page(): void
    {
        foreach ([$this->admin, $this->cajero] as $usuario) {
            $this->actingAs($usuario)->get(route('panel.inicio'))
                ->assertOk()
                ->assertSee('Mesa 4 + 5 · Ronda 2 · Luis')
                ->assertSee('Se cobra por cuenta')
                ->assertDontSee('Sin método');
        }
    }

    public function test_the_kitchen_never_receives_amounts_of_any_order(): void
    {
        $this->actingAs($this->cocina);

        $lista = $this->get(route('panel.pedidos.index', ['estado' => 'en_preparacion']))->assertOk();
        $lista->assertSee('#41')->assertSee('#40')->assertSee('Mesa 4 + 5 · Ronda 2 · Luis');

        foreach ([$this->ronda, $this->publico] as $pedido) {
            $detalle = $this->get(route('panel.pedidos.show', $pedido), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('Pedido #'.$pedido->numero);

            foreach ([$lista, $detalle] as $respuesta) {
                $respuesta->assertDontSee('S/')->assertDontSee('73.50')->assertDontSee('58.40')->assertDontSee('36.75');
            }
        }

        $novedades = $this->getJson(route('panel.pedidos.novedades', ['desde' => 0]))->assertOk();
        $this->assertStringNotContainsString('73.5', $novedades->getContent());
        $this->assertStringNotContainsString('58.4', $novedades->getContent());
        $this->assertNotEmpty($novedades->json('nuevos'));

        foreach ($novedades->json('nuevos') as $nuevo) {
            $this->assertArrayNotHasKey('total', $nuevo);
        }
    }

    public function test_admin_and_cashier_still_see_the_amounts(): void
    {
        $this->actingAs($this->cajero)->get(route('panel.pedidos.index', ['estado' => 'en_preparacion']))->assertSee('S/')->assertSee('73.50')->assertSee('58.40');
        $this->getJson(route('panel.pedidos.novedades', ['desde' => 0]))->assertJsonPath('nuevos.0.total', '58.40');
    }

    public function test_a_new_round_sounds_in_the_kitchen_but_not_as_a_new_order_for_the_cashier(): void
    {
        $cocina = $this->actingAs($this->cocina)->getJson(route('panel.pedidos.novedades', ['desde' => $this->ronda->id - 1]))->assertOk();
        $ronda = collect($cocina->json('nuevos'))->firstWhere('numero', 41);
        $this->assertNotNull($ronda, 'La cocina recibe la ronda nueva para avisar con sonido.');
        $this->assertSame(['Mesa 4 + 5 · Ronda 2 · Luis', 'Salón'], [$ronda['cliente'], $ronda['tipo']]);

        $cajero = $this->actingAs($this->cajero)->getJson(route('panel.pedidos.novedades', ['desde' => 0]))->assertOk();
        $this->assertSame([40], array_column($cajero->json('nuevos'), 'numero'));
        $this->assertSame(0, $cajero->json('badge'), 'Una ronda nunca suma al badge de nuevos.');
    }

    public function test_the_order_list_does_not_run_one_query_per_salon_order(): void
    {
        foreach (range(3, 12) as $ronda) {
            Pedido::factory()->salon($this->cuenta, $ronda)->create(['creado_por' => $this->mesero->id]);
        }

        $this->actingAs($this->cajero->fresh());
        $consultas = 0;
        DB::listen(function () use (&$consultas): void {
            $consultas++;
        });

        $this->get(route('panel.pedidos.index', ['estado' => 'en_preparacion']))->assertOk()->assertSee('Ronda 12');

        $this->assertLessThan(25, $consultas, "La lista hizo {$consultas} consultas con 12 rondas.");
    }

    public function test_a_salon_order_is_never_visible_through_public_tracking(): void
    {
        $codigo = Pedido::withoutGlobalScopes()->whereKey($this->ronda->id)->value('codigo_seguimiento');
        $slug = $this->restaurante->slug;

        $this->post(route('logout'));
        $this->app['auth']->forgetGuards();

        $this->get(route('vitrina.pedido.seguimiento', [$slug, $codigo]))->assertNotFound();
        $this->getJson(route('vitrina.pedido.estado', [$slug, $codigo]))->assertNotFound();
    }

    public function test_the_waiter_does_not_enter_the_orders_panel(): void
    {
        $this->actingAs($this->mesero)->get(route('panel.pedidos.index'))->assertForbidden();
        $this->actingAs($this->mesero)->get(route('panel.pedidos.show', $this->ronda), ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
    }
}
