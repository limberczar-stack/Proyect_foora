<?php

namespace Tests\Feature\Panel;

use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoItemOpcion;
use App\Models\Restaurante;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PedidoPanelTest extends TestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    private Restaurante $restaurante;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-07 15:00', 'America/Lima'));

        $this->restaurante = Restaurante::factory()->create(['sonido_nuevos_pedidos' => true]);
        $this->admin = User::factory()->admin($this->restaurante)->create();
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function pedido(array $atributos = []): Pedido
    {
        return Pedido::factory()->create(['restaurante_id' => $this->restaurante->id, ...$atributos]);
    }

    private function detalle(Pedido $pedido): TestResponse
    {
        return $this->get(route('panel.pedidos.show', $pedido), self::AJAX);
    }

    public function test_tabs_show_counts_and_finished_tabs_only_count_today(): void
    {
        $this->pedido(['numero' => 1, 'estado' => 'nuevo']);
        $this->pedido(['numero' => 2, 'estado' => 'nuevo']);
        $this->pedido(['numero' => 3, 'estado' => 'en_preparacion']);
        $this->pedido(['numero' => 4, 'nombre_cliente' => 'Entregado hoy', 'estado' => 'entregado', 'entregado_at' => now()->subHour()]);
        $this->pedido(['numero' => 5, 'nombre_cliente' => 'Entregado ayer', 'estado' => 'entregado', 'entregado_at' => now()->subDay()]);
        $this->pedido(['numero' => 6, 'estado' => 'cancelado', 'cancelado_at' => now()->subDays(2)]);

        $this->actingAs($this->admin)
            ->get(route('panel.pedidos.index'))
            ->assertOk()
            ->assertSeeInOrder(['Nuevos', '2', 'En preparación', '1', 'Listos', '0', 'En camino', '0', 'Entregados hoy', '1', 'Cancelados hoy', '0']);

        $this->get(route('panel.pedidos.index', ['estado' => 'entregado']))
            ->assertSee('Entregado hoy')
            ->assertDontSee('Entregado ayer');
    }

    public function test_today_is_the_restaurant_day_not_the_server_day(): void
    {
        // 23:30 en Lima del día anterior son las 04:30 UTC de hoy: no es de hoy en Lima.
        $this->pedido(['numero' => 7, 'nombre_cliente' => 'Cliente de ayer', 'estado' => 'entregado', 'entregado_at' => Carbon::parse('2026-11-06 23:30', 'America/Lima')->utc()]);
        $this->pedido(['numero' => 8, 'nombre_cliente' => 'Cliente de hoy', 'estado' => 'entregado', 'entregado_at' => Carbon::parse('2026-11-07 00:10', 'America/Lima')->utc()]);

        $this->actingAs($this->admin)
            ->get(route('panel.pedidos.index', ['estado' => 'entregado']))
            ->assertSee('Cliente de hoy')
            ->assertDontSee('Cliente de ayer');
    }

    public function test_type_filter_and_search_by_number_name_or_phone(): void
    {
        $this->pedido(['numero' => 11, 'tipo' => 'delivery', 'nombre_cliente' => 'Lucía Paredes', 'telefono_cliente' => '911111111']);
        $this->pedido(['numero' => 12, 'tipo' => 'recojo', 'nombre_cliente' => 'Jorge Ramírez', 'telefono_cliente' => '922222222']);
        $this->actingAs($this->admin);

        $this->get(route('panel.pedidos.index', ['tipo' => 'delivery']))->assertSee('Lucía')->assertDontSee('Jorge');
        $this->get(route('panel.pedidos.index', ['q' => 'jorge']))->assertSee('Jorge')->assertDontSee('Lucía');
        $this->get(route('panel.pedidos.index', ['q' => '#11']))->assertSee('Lucía')->assertDontSee('Jorge');
        $this->get(route('panel.pedidos.index', ['q' => '2222']))->assertSee('Jorge')->assertDontSee('Lucía');
        $this->get(route('panel.pedidos.index', ['q' => 'nadie']))->assertSee('Ningún pedido coincide con');
    }

    public function test_empty_state_per_tab(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('panel.pedidos.index'))->assertSee('No hay pedidos nuevos.');
        $this->get(route('panel.pedidos.index', ['estado' => 'cancelado']))->assertSee('No hay pedidos cancelados hoy.');
    }

    public function test_listing_paginates_by_twenty(): void
    {
        Pedido::factory()->count(23)->sequence(fn ($s) => ['numero' => $s->index + 1])->create(['restaurante_id' => $this->restaurante->id]);

        $this->actingAs($this->admin)->get(route('panel.pedidos.index'))->assertSee('1–20 de 23');
        $this->get(route('panel.pedidos.index', ['page' => 2]))->assertSee('21–23 de 23');
    }

    public function test_detail_shows_customer_items_totals_and_history_with_user(): void
    {
        $pedido = $this->pedido([
            'tipo' => 'delivery',
            'numero' => 21,
            'nombre_cliente' => 'Ana Torres',
            'telefono_cliente' => '987654321',
            'direccion_entrega' => 'Jr. Lima 123',
            'referencia_entrega' => 'Casa azul',
            'subtotal' => 30,
            'costo_delivery' => 5,
            'total' => 35,
        ]);
        $item = PedidoItem::create(['pedido_id' => $pedido->id, 'nombre' => 'Chaufa', 'variante_nombre' => 'Personal', 'cantidad' => 2, 'precio_unitario' => 15, 'subtotal' => 30, 'observacion' => 'Sin cebolla']);
        PedidoItemOpcion::create(['pedido_item_id' => $item->id, 'grupo_nombre' => 'Adicionales', 'nombre' => 'Tocino', 'precio_extra' => 3]);
        $pedido->historial()->create(['estado_anterior' => null, 'estado_nuevo' => 'nuevo', 'nota' => 'Pedido recibido desde la vitrina.']);

        $this->actingAs($this->admin);
        $this->detalle($pedido)
            ->assertOk()
            ->assertSee('Pedido #21')
            ->assertSee('Ana Torres')
            ->assertSee('987654321')
            ->assertSee('tel:+51987654321', false)
            ->assertSee('https://wa.me/51987654321', false)
            ->assertSee('Jr. Lima 123')
            ->assertSee('Casa azul')
            ->assertSee('Personal · Tocino (+3.00)')
            ->assertSee('Nota: Sin cebolla')
            ->assertSee('35.00')
            ->assertSee('Vitrina')
            ->assertSee('Aceptar pedido')
            ->assertSee('Rechazar pedido');
    }

    public function test_notify_customer_message_depends_on_state_and_includes_tracking_link(): void
    {
        $pedido = $this->pedido(['estado' => 'listo', 'tipo' => 'recojo', 'numero' => 5, 'nombre_cliente' => 'Ana Torres']);

        $this->actingAs($this->admin);
        $html = $this->detalle($pedido)->getContent();

        $this->assertStringContainsString(rawurlencode("Hola Ana, tu pedido #5 está listo. Ya puedes recogerlo en {$this->restaurante->nombre}."), $html);
        $this->assertStringContainsString(rawurlencode(route('vitrina.pedido.seguimiento', [$this->restaurante->slug, $pedido->codigo_seguimiento])), $html);
    }

    public function test_full_page_with_selected_order_and_redirect_without_ajax(): void
    {
        $pedido = $this->pedido(['numero' => 31, 'nombre_cliente' => 'Elena Ruiz']);
        $this->actingAs($this->admin);

        $this->get(route('panel.pedidos.index', ['pedido' => $pedido->id]))->assertSee('Pedido #31')->assertSee('data-detalle="'.$pedido->id.'"', false);
        $this->get(route('panel.pedidos.show', $pedido))->assertRedirect(route('panel.pedidos.index', ['estado' => 'nuevo', 'pedido' => $pedido->id]));
    }

    public function test_tracking_code_never_appears_in_the_listing(): void
    {
        $pedido = $this->pedido();

        $this->actingAs($this->admin)->get(route('panel.pedidos.index'))->assertDontSee($pedido->codigo_seguimiento);
    }

    public function test_customer_text_is_escaped_in_list_and_detail(): void
    {
        $ataque = '</script><script>alert(1)</script>';
        $pedido = $this->pedido([
            'tipo' => 'delivery',
            'nombre_cliente' => "<img src=x onerror=alert(2)> {$ataque}",
            'direccion_entrega' => $ataque,
            'referencia_entrega' => $ataque,
            'referencia_pago' => '<b>OP</b>',
        ]);
        PedidoItem::create(['pedido_id' => $pedido->id, 'nombre' => $ataque, 'cantidad' => 1, 'precio_unitario' => 1, 'subtotal' => 1, 'observacion' => $ataque]);

        $this->actingAs($this->admin);

        foreach ([$this->get(route('panel.pedidos.index'))->getContent(), $this->detalle($pedido)->getContent()] as $html) {
            $this->assertStringNotContainsString('<script>alert(1)', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringContainsString('&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        }
    }

    public function test_cashier_has_full_access(): void
    {
        $pedido = $this->pedido();
        $this->actingAs(User::factory()->cajero($this->restaurante)->create());

        $this->get(route('panel.pedidos.index'))->assertOk();
        $this->detalle($pedido)->assertOk()->assertSee('Aceptar pedido');
        $this->patchJson(route('panel.pedidos.estado', $pedido), ['desde' => 'nuevo', 'destino' => 'en_preparacion'])->assertOk();
    }

    public function test_kitchen_only_sees_orders_in_preparation_and_ready_and_only_marks_ready(): void
    {
        $nuevo = $this->pedido(['numero' => 41, 'nombre_cliente' => 'Cliente Nuevo']);
        $preparando = $this->pedido(['numero' => 42, 'estado' => 'en_preparacion', 'nombre_cliente' => 'Cliente Cocina', 'telefono_cliente' => '987654321', 'tipo' => 'delivery', 'direccion_entrega' => 'Jr. Secreto 1']);
        $listo = $this->pedido(['numero' => 43, 'estado' => 'listo']);
        $cocina = User::factory()->personal('cocina', $this->restaurante)->create();

        $this->actingAs($cocina);

        $this->get(route('panel.pedidos.index'))
            ->assertOk()
            ->assertSee('En preparación')
            ->assertSee('Listos')
            ->assertDontSee('Nuevos')
            ->assertDontSee('Entregados hoy')
            ->assertSee('Cliente Cocina');

        $this->get(route('panel.pedidos.index', ['estado' => 'nuevo']))->assertSessionHasErrors('estado');
        $this->detalle($nuevo)->assertForbidden();

        $this->detalle($preparando)
            ->assertOk()
            ->assertSee('Marcar listo')
            ->assertDontSee('987654321')
            ->assertDontSee('Jr. Secreto')
            ->assertDontSee('wa.me')
            ->assertDontSee('Cancelar pedido')
            ->assertDontSee('Validar pago');

        $this->patchJson(route('panel.pedidos.estado', $preparando), ['desde' => 'en_preparacion', 'destino' => 'cancelado', 'motivo' => 'otro'])->assertForbidden();
        $this->patchJson(route('panel.pedidos.estado', $nuevo), ['desde' => 'nuevo', 'destino' => 'en_preparacion'])->assertForbidden();
        $this->patchJson(route('panel.pedidos.estado', $listo), ['desde' => 'listo', 'destino' => 'entregado'])->assertForbidden();
        $this->patchJson(route('panel.pedidos.pago', $preparando), ['accion' => 'validar'])->assertForbidden();
        $this->patchJson(route('panel.pedidos.estado', $preparando), ['desde' => 'en_preparacion', 'destino' => 'listo'])->assertOk();

        $this->assertSame('listo', $preparando->fresh()->estado);
        $this->assertSame('nuevo', $nuevo->fresh()->estado);
    }

    public function test_waiter_delivery_person_and_superadmin_get_forbidden(): void
    {
        $pedido = $this->pedido();

        foreach (['mesero', 'repartidor'] as $rol) {
            $this->actingAs(User::factory()->personal($rol, $this->restaurante)->create());

            $this->get(route('panel.pedidos.index'))->assertForbidden();
            $this->detalle($pedido)->assertForbidden();
            $this->getJson(route('panel.pedidos.novedades', ['desde' => 0]))->assertForbidden();
            $this->patchJson(route('panel.pedidos.estado', $pedido), ['desde' => 'nuevo', 'destino' => 'en_preparacion'])->assertForbidden();
        }

        $this->actingAs(User::factory()->superadmin()->create())->get(route('panel.pedidos.index'))->assertForbidden();
        $this->assertSame('nuevo', $pedido->fresh()->estado);
    }

    public function test_orders_from_another_restaurant_are_not_found_or_listed(): void
    {
        $otro = Restaurante::factory()->create();
        $ajeno = Pedido::factory()->create(['restaurante_id' => $otro->id, 'nombre_cliente' => 'Cliente Ajeno']);
        $this->pedido(['nombre_cliente' => 'Cliente Propio']);

        $this->actingAs($this->admin);

        $this->get(route('panel.pedidos.index'))->assertSee('Cliente Propio')->assertDontSee('Cliente Ajeno');
        $this->get(route('panel.pedidos.index', ['pedido' => $ajeno->id]))->assertOk()->assertDontSee('Cliente Ajeno');
        $this->detalle($ajeno)->assertNotFound();
        $this->patchJson(route('panel.pedidos.estado', $ajeno), ['desde' => 'nuevo', 'destino' => 'en_preparacion'])->assertNotFound();
        $this->patchJson(route('panel.pedidos.pago', $ajeno), ['accion' => 'validar'])->assertNotFound();

        $this->assertSame('nuevo', $ajeno->fresh()->estado);
    }

    public function test_updates_endpoint_returns_counts_badge_and_only_newer_orders_of_the_restaurant(): void
    {
        $viejo = $this->pedido(['numero' => 1]);
        $nuevo = $this->pedido(['numero' => 2, 'nombre_cliente' => 'Ana María Torres', 'telefono_cliente' => '987654321']);
        $autoaceptado = $this->pedido(['numero' => 3, 'estado' => 'en_preparacion']);
        Pedido::factory()->create(['restaurante_id' => Restaurante::factory()->create()->id]);

        $this->actingAs($this->admin)
            ->getJson(route('panel.pedidos.novedades', ['desde' => $viejo->id]))
            ->assertOk()
            ->assertJsonPath('badge', 2)
            ->assertJsonPath('contadores.nuevo', 2)
            ->assertJsonPath('contadores.en_preparacion', 1)
            ->assertJsonPath('ultimo_id', $autoaceptado->id)
            ->assertJsonCount(2, 'nuevos')
            ->assertJsonPath('nuevos.0.numero', 2)
            ->assertJsonPath('nuevos.0.cliente', 'Ana')
            ->assertJsonPath('nuevos.1.estado', 'En preparación')
            ->assertJsonMissing(['telefono_cliente' => '987654321'])
            ->assertDontSee('987654321');

        $this->getJson(route('panel.pedidos.novedades', ['desde' => $autoaceptado->id]))->assertJsonCount(0, 'nuevos');
        $this->getJson(route('panel.pedidos.novedades', ['desde' => $nuevo->id, 'tipo' => 'delivery']))->assertJsonPath('contadores.nuevo', 0);
    }

    public function test_kitchen_updates_only_include_their_states(): void
    {
        $this->pedido(['numero' => 1]);
        $this->pedido(['numero' => 2, 'estado' => 'en_preparacion']);

        $this->actingAs(User::factory()->personal('cocina', $this->restaurante)->create())
            ->getJson(route('panel.pedidos.novedades', ['desde' => 0]))
            ->assertJsonCount(1, 'nuevos')
            ->assertJsonPath('nuevos.0.numero', 2)
            ->assertJsonMissingPath('contadores.nuevo');
    }

    public function test_menu_badge_shows_the_real_count_of_new_orders(): void
    {
        $this->pedido(['numero' => 1]);
        $this->pedido(['numero' => 2]);
        $this->pedido(['numero' => 3, 'estado' => 'en_preparacion']);

        $this->actingAs($this->admin)
            ->get(route('panel.carta.productos.index'))
            ->assertSee('data-nav-badge="pedidos"', false)
            ->assertSee('<span data-nav-badge-numero>2</span>', false)
            ->assertSee(route('panel.pedidos.index'), false);
    }

    public function test_sound_button_only_when_the_restaurant_enables_it(): void
    {
        $this->actingAs($this->admin)->get(route('panel.pedidos.index'))->assertSee('Activar sonido');

        $this->restaurante->update(['sonido_nuevos_pedidos' => false]);
        $this->admin->unsetRelations();
        $this->get(route('panel.pedidos.index'))->assertDontSee('Activar sonido');
    }

    public function test_query_count_does_not_grow_with_the_orders(): void
    {
        $this->actingAs($this->admin);

        $pocos = $this->consultas(3);
        $muchos = $this->consultas(20);

        $this->assertSame($pocos['lista'], $muchos['lista']);
        $this->assertSame($pocos['detalle'], $muchos['detalle']);
    }

    /**
     * @return array{lista: int, detalle: int}
     */
    private function consultas(int $cantidad): array
    {
        $metodo = MetodoPago::firstOrCreate(['codigo' => 'efectivo'], ['nombre' => 'Efectivo']);
        $base = (int) Pedido::max('numero');
        $pedidos = Pedido::factory()->count($cantidad)->sequence(fn ($s) => ['numero' => $base + $s->index + 1])->create(['restaurante_id' => $this->restaurante->id, 'metodo_pago_id' => $metodo->id]);
        $pedido = $pedidos->last();

        foreach (range(1, $cantidad) as $i) {
            $item = PedidoItem::create(['pedido_id' => $pedido->id, 'nombre' => "Plato {$i}", 'cantidad' => 1, 'precio_unitario' => 1, 'subtotal' => 1]);
            PedidoItemOpcion::create(['pedido_item_id' => $item->id, 'grupo_nombre' => 'G', 'nombre' => "O{$i}", 'precio_extra' => 0]);
            $pedido->historial()->create(['estado_anterior' => 'nuevo', 'estado_nuevo' => 'nuevo', 'user_id' => $this->admin->id]);
        }

        $contar = function (callable $peticion): int {
            // El usuario de actingAs guarda sus relaciones entre peticiones; cada medición parte limpia.
            $this->admin->unsetRelations();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $peticion();
            $total = collect(DB::getQueryLog())->reject(fn (array $q): bool => str_contains($q['query'], 'sessions') || str_contains($q['query'], '"cache'))->count();
            DB::disableQueryLog();

            return $total;
        };

        return [
            'lista' => $contar(fn () => $this->get(route('panel.pedidos.index'))->assertOk()),
            'detalle' => $contar(fn () => $this->detalle($pedido)->assertOk()),
        ];
    }

    public function test_demo_seeder_creates_orders_in_every_state(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $buenSabor = Restaurante::where('slug', 'el-buen-sabor')->firstOrFail();
        $estados = Pedido::withoutGlobalScopes()->where('restaurante_id', $buenSabor->id)->pluck('estado')->unique()->sort()->values()->all();

        $this->assertSame(['cancelado', 'en_camino', 'en_preparacion', 'entregado', 'listo', 'nuevo'], $estados);
        $this->assertSame(range(1, 12), Pedido::withoutGlobalScopes()->where('restaurante_id', $buenSabor->id)->orderBy('numero')->pluck('numero')->all());
        $this->assertTrue(User::where('email', 'cocina@foora.test')->where('rol', 'cocina')->exists());

        $this->actingAs(User::where('email', 'admin@foora.test')->first())
            ->get(route('panel.pedidos.index'))
            ->assertOk()
            ->assertSee('Lucía Paredes');
    }
}
