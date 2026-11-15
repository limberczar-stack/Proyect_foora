<?php

namespace Tests\Feature\Mesas;

use App\Http\Middleware\EnsureLocalOperativo;
use App\Models\ActividadLog;
use App\Models\Alerta;
use App\Models\Area;
use App\Models\CategoriaProducto;
use App\Models\Cuenta;
use App\Models\GrupoOpcion;
use App\Models\Mesa;
use App\Models\Opcion;
use App\Models\Pedido;
use App\Models\PedidoHistorial;
use App\Models\PedidoItem;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\User;
use App\Services\CrearPedidoSalon;
use App\Services\GestionarCuentas;
use App\Services\GestionarPedidoSalon;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Enviar rondas de una cuenta de salón a la cocina (CrearPedidoSalon).
 */
class RondasSalonTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    private User $cajero;

    private User $mesero;

    private Cuenta $cuenta;

    private Producto $lomo;

    private Producto $chaufa;

    private ProductoVariante $familiar;

    private Opcion $huevo;

    /** @var list<Mesa> */
    private array $mesas;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 13:00', 'America/Lima'));

        // Cerrado y con pedido mínimo alto: al salón no le afecta ninguna de las dos cosas.
        $this->restaurante = Restaurante::factory()->conModulos('mesas')->cerrado()->create(['pedido_minimo' => 500, 'acepta_pedidos_auto' => false, 'tiempo_prep_min' => 25]);
        $this->admin = User::factory()->admin($this->restaurante)->create();
        $this->cajero = User::factory()->cajero($this->restaurante)->create();
        $this->mesero = User::factory()->personal('mesero', $this->restaurante)->create(['name' => 'Luis Chávez']);

        $categoria = CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Platos']);
        $this->lomo = Producto::factory()->for($categoria, 'categoria')->create(['nombre' => 'Lomo saltado', 'precio' => 36.50]);
        $this->chaufa = Producto::factory()->for($categoria, 'categoria')->create(['nombre' => 'Chaufa', 'tipo' => 'variantes', 'precio' => 22]);
        $this->familiar = ProductoVariante::factory()->for($this->chaufa)->create(['nombre' => 'Familiar', 'precio' => 48.00]);
        $extras = GrupoOpcion::factory()->for($this->restaurante)->multiple()->create(['nombre' => 'Extras']);
        $this->huevo = Opcion::factory()->for($extras, 'grupo')->create(['nombre' => 'Huevo frito', 'precio_extra' => 3.00]);
        $this->chaufa->gruposOpcion()->attach($extras);

        $area = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Salón']);
        $this->mesas = collect(['4', '5', '6', '7'])->map(fn (string $codigo): Mesa => Mesa::factory()->for($area)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => $codigo]))->all();
        $this->cuenta = app(GestionarCuentas::class)->abrir($this->mesero, [$this->mesas[0]->id, $this->mesas[1]->id], 4);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function lineaLomo(int $cantidad = 2, ?string $observacion = null): array
    {
        return ['producto_id' => $this->lomo->id, 'variante_id' => null, 'opciones' => [], 'cantidad' => $cantidad, 'observacion' => $observacion];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineaChaufa(): array
    {
        return ['producto_id' => $this->chaufa->id, 'variante_id' => $this->familiar->id, 'opciones' => [$this->huevo->id], 'cantidad' => 1, 'observacion' => 'Sin cebolla'];
    }

    /**
     * @param  list<array<string, mixed>>|null  $lineas
     */
    private function enviar(?array $lineas = null, ?string $clave = null, ?Cuenta $cuenta = null): TestResponse
    {
        return $this->postJson(route('panel.mesas.cuentas.pedido.store', $cuenta ?? $this->cuenta), [
            'lineas' => $lineas ?? [$this->lineaLomo(), $this->lineaChaufa()],
            'idempotency_key' => $clave ?? Str::uuid()->toString(),
        ]);
    }

    public function test_a_round_is_a_salon_order_in_preparation_with_server_prices(): void
    {
        $this->actingAs($this->mesero)->enviar()
            ->assertOk()
            ->assertJsonPath('mensaje', 'Ronda 1 enviada a cocina (pedido #1).')
            ->assertJsonPath('pedido.ronda', 1);

        $pedido = Pedido::sole();
        $this->assertSame(
            ['salon', 'local', 'en_preparacion', 'pendiente', $this->cuenta->id, 1, $this->mesero->id, '0.00', '124.00', '124.00', 25],
            [$pedido->tipo, $pedido->origen, $pedido->estado, $pedido->estado_pago, $pedido->cuenta_id, $pedido->ronda, $pedido->creado_por, $pedido->costo_delivery, $pedido->subtotal, $pedido->total, $pedido->tiempo_estimado_min],
        );
        $this->assertNotNull($pedido->aceptado_at);
        $this->assertNull($pedido->nombre_cliente);
        $this->assertNull($pedido->telefono_cliente);
        $this->assertNull($pedido->metodo_pago_id);
        $this->assertNull($pedido->mesa_id);

        $items = PedidoItem::orderBy('id')->get();
        $this->assertSame(['Lomo saltado', 'Chaufa'], $items->pluck('nombre')->all());
        $this->assertSame(['73.00', '51.00'], $items->pluck('subtotal')->all());
        $this->assertSame([null, 'Sin cebolla'], $items->pluck('observacion')->all());
        $this->assertSame('Huevo frito', $items[1]->opciones->sole()->nombre);

        $this->assertSame(['en_preparacion', 'Ronda 1 de la cuenta #1.', $this->mesero->id], [
            PedidoHistorial::sole()->estado_nuevo, PedidoHistorial::sole()->nota, PedidoHistorial::sole()->user_id,
        ]);
        $this->assertSame(1, ActividadLog::where('accion', 'envio_ronda')->where('modulo', 'mesas')->count());
    }

    public function test_a_round_does_not_create_the_new_order_alert_nor_bumps_the_account_version(): void
    {
        $this->actingAs($this->mesero)->enviar()->assertOk();

        $this->assertSame(0, Alerta::count());
        $this->assertSame(1, $this->cuenta->fresh()->version);
        $this->assertSame(0, Pedido::where('estado', 'nuevo')->count());

        $this->actingAs($this->cajero)->getJson(route('panel.pedidos.novedades', ['desde' => 0]))->assertJsonPath('badge', 0);
    }

    public function test_the_server_ignores_any_price_sent_by_the_browser(): void
    {
        $linea = [...$this->lineaLomo(1), 'precio' => 0.10, 'subtotal' => 0.10, 'precio_unitario' => 0.10];

        $this->actingAs($this->mesero)->postJson(route('panel.mesas.cuentas.pedido.store', $this->cuenta), [
            'lineas' => [$linea],
            'idempotency_key' => Str::uuid()->toString(),
            'total' => 0.10,
        ])->assertOk();

        $this->assertSame(['36.50', '36.50'], [Pedido::sole()->subtotal, Pedido::sole()->total]);
    }

    public function test_numbering_is_shared_with_public_orders_and_rounds_count_per_account(): void
    {
        Pedido::factory()->create(['restaurante_id' => $this->restaurante->id, 'numero' => 7]);
        $otra = app(GestionarCuentas::class)->abrir($this->cajero, [$this->mesas[2]->id], 2);

        $this->actingAs($this->mesero);
        $this->enviar()->assertOk()->assertJsonPath('pedido.numero', 8)->assertJsonPath('pedido.ronda', 1);
        $this->enviar()->assertOk()->assertJsonPath('pedido.numero', 9)->assertJsonPath('pedido.ronda', 2);
        $this->enviar(cuenta: $otra)->assertOk()->assertJsonPath('pedido.numero', 10)->assertJsonPath('pedido.ronda', 1);

        $this->assertSame([7, 8, 9, 10], Pedido::orderBy('numero')->pluck('numero')->all());
    }

    public function test_the_same_key_returns_the_same_round(): void
    {
        $clave = Str::uuid()->toString();
        $this->actingAs($this->mesero);

        $primera = $this->enviar(clave: $clave)->assertOk()->json('pedido.id');
        $segunda = $this->enviar(lineas: [$this->lineaLomo(5)], clave: $clave)->assertOk()->json('pedido.id');

        $this->assertSame($primera, $segunda);
        $this->assertSame(1, Pedido::count());
        $this->assertSame(2, PedidoItem::count());
    }

    public function test_a_key_used_in_another_account_or_a_public_order_is_rejected(): void
    {
        $clave = Str::uuid()->toString();
        $otra = app(GestionarCuentas::class)->abrir($this->cajero, [$this->mesas[2]->id], 2);
        $this->actingAs($this->mesero);

        $this->enviar(clave: $clave, cuenta: $otra)->assertOk();
        $this->enviar(clave: $clave)->assertConflict()->assertJsonPath('message', CrearPedidoSalon::MENSAJE_CLAVE_AJENA);

        $publica = Str::uuid()->toString();
        Pedido::factory()->create(['restaurante_id' => $this->restaurante->id, 'idempotency_key' => $publica]);
        $this->enviar(clave: $publica)->assertConflict();

        $this->assertSame(0, Pedido::where('cuenta_id', $this->cuenta->id)->count());
    }

    /**
     * @return array<string, array{0: \Closure(self): list<array<string, mixed>>, 1: string}>
     */
    public static function lineasInvalidas(): array
    {
        return [
            'cantidad cero' => [fn (self $t): array => [$t->lineaLomo(1), $t->lineaLomo(0)], 'lineas.1'],
            'cantidad 51' => [fn (self $t): array => [$t->lineaLomo(51)], 'lineas.0'],
            'observación de 201' => [fn (self $t): array => [$t->lineaLomo(1, str_repeat('a', 201))], 'lineas.0.observacion'],
            '31 líneas' => [fn (self $t): array => array_fill(0, 31, $t->lineaLomo(1)), 'lineas'],
            'sin líneas' => [fn (self $t): array => [], 'lineas'],
        ];
    }

    #[DataProvider('lineasInvalidas')]
    public function test_the_same_limits_as_the_public_cart_apply(\Closure $lineas, string $campo): void
    {
        $this->actingAs($this->mesero)->enviar($lineas($this))->assertUnprocessable()->assertJsonValidationErrors($campo);

        $this->assertSame(0, Pedido::count());
    }

    public function test_thirty_lines_and_fifty_units_are_accepted(): void
    {
        $this->actingAs($this->mesero)->enviar(array_fill(0, 30, $this->lineaLomo(50)))->assertOk();

        $this->assertSame(30, PedidoItem::count());
    }

    public function test_a_sold_out_or_changed_product_marks_only_its_line_and_nothing_is_saved(): void
    {
        $this->chaufa->update(['disponible' => false]);

        $respuesta = $this->actingAs($this->mesero)->enviar([$this->lineaLomo(), $this->lineaChaufa(), $this->lineaLomo(1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lineas.1');

        $this->assertSame(['lineas.1'], array_keys($respuesta->json('errors')), 'Solo la línea que falló se marca.');
        $this->assertSame(0, Pedido::count());
        $this->assertSame(0, PedidoItem::count());

        // El borrador sigue siendo válido sin esa línea.
        $this->enviar([$this->lineaLomo(), $this->lineaLomo(1)])->assertOk();
    }

    public function test_a_product_of_another_restaurant_is_a_line_error(): void
    {
        $ajeno = Producto::factory()->for(CategoriaProducto::factory()->for(Restaurante::factory())->create(), 'categoria')->create(['precio' => 1]);

        $this->actingAs($this->mesero)->enviar([$this->lineaLomo(), ['producto_id' => $ajeno->id, 'variante_id' => null, 'opciones' => [], 'cantidad' => 1, 'observacion' => null]])
            ->assertUnprocessable()->assertJsonValidationErrors('lineas.1');

        $this->assertSame(0, Pedido::count());
    }

    public function test_the_draft_can_be_priced_without_saving_anything(): void
    {
        $this->actingAs($this->mesero)
            ->postJson(route('panel.mesas.cuentas.pedido.validar', $this->cuenta), ['lineas' => [$this->lineaLomo(), $this->lineaChaufa()]])
            ->assertOk()
            ->assertJsonPath('subtotal', '124.00')
            ->assertJsonPath('lineas.0.valida', true)
            ->assertJsonPath('lineas.1.subtotal', '51.00');

        $this->assertSame(0, Pedido::count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function finales(): array
    {
        return ['cerrada' => ['cerrada'], 'anulada' => ['anulada'], 'fusionada' => ['fusionada']];
    }

    #[DataProvider('finales')]
    public function test_a_round_only_goes_to_an_open_account(string $estado): void
    {
        $gestion = app(GestionarCuentas::class);

        match ($estado) {
            'cerrada' => $gestion->cerrarExterno($this->cajero, $this->cuenta, 1, 0),
            'anulada' => $gestion->anular($this->cajero, $this->cuenta, 1, 'Se abrió por error'),
            'fusionada' => $gestion->fusionar($this->cajero, $gestion->abrir($this->cajero, [$this->mesas[3]->id], 2), 1, $this->cuenta, 1),
        };

        $this->actingAs($this->mesero)->enviar()->assertConflict()->assertJsonPath('message', GestionarCuentas::MENSAJE_YA_NO_ABIERTA);

        $this->assertSame(0, Pedido::count());
    }

    public function test_a_round_on_a_bill_request_reopens_the_account_and_resolves_its_alert(): void
    {
        $gestion = app(GestionarPedidoSalon::class);
        $this->actingAs($this->mesero);
        $this->enviar()->assertOk();

        $gestion->pedirPrecuenta($this->mesero, $this->cuenta, 1);
        $this->assertSame(['precuenta', 2], [$this->cuenta->fresh()->estado, $this->cuenta->fresh()->version]);
        $this->assertSame(1, Alerta::where('tipo', 'precuenta_pedida')->whereNull('resuelta_at')->count());

        $this->enviar()->assertOk()->assertJsonPath('pedido.ronda', 2);

        $this->assertSame(['abierta', 2], [$this->cuenta->fresh()->estado, $this->cuenta->fresh()->version], 'La ronda reabre la cuenta sin subir version.');
        $this->assertSame(0, Alerta::where('tipo', 'precuenta_pedida')->whereNull('resuelta_at')->count());

        // Una precuenta posterior crea una alerta nueva; pedirla dos veces no la duplica.
        $gestion->pedirPrecuenta($this->mesero, $this->cuenta, 2);
        $gestion->pedirPrecuenta($this->mesero, $this->cuenta, 3);
        $this->assertSame(2, Alerta::where('tipo', 'precuenta_pedida')->count());
        $this->assertSame(1, Alerta::where('tipo', 'precuenta_pedida')->whereNull('resuelta_at')->count());
        $this->assertSame(3, $this->cuenta->fresh()->version);
    }

    public function test_floor_roles_send_rounds_and_kitchen_or_delivery_cannot(): void
    {
        foreach ([$this->admin, $this->cajero, $this->mesero] as $usuario) {
            $this->actingAs($usuario)->enviar()->assertOk();
            $this->get(route('panel.mesas.cuentas.pedido.create', $this->cuenta))->assertOk();
        }

        foreach (['cocina', 'repartidor'] as $rol) {
            $this->actingAs(User::factory()->personal($rol, $this->restaurante)->create());
            $this->enviar()->assertForbidden();
            $this->get(route('panel.mesas.cuentas.pedido.create', $this->cuenta))->assertForbidden();
            $this->postJson(route('panel.mesas.cuentas.pedido.validar', $this->cuenta), ['lineas' => [$this->lineaLomo()]])->assertForbidden();
        }

        $this->assertSame(3, Pedido::count());
    }

    public function test_an_account_of_another_restaurant_is_404(): void
    {
        $otro = Restaurante::factory()->conModulos('mesas')->create();
        $otroAdmin = User::factory()->admin($otro)->create();
        $mesa = Mesa::factory()->for(Area::factory()->create(['restaurante_id' => $otro->id]))->create(['restaurante_id' => $otro->id]);
        $ajena = app(GestionarCuentas::class)->abrir($otroAdmin, [$mesa->id], 2);

        $this->actingAs($this->mesero);
        $this->enviar(cuenta: $ajena)->assertNotFound();
        $this->get(route('panel.mesas.cuentas.pedido.create', $ajena))->assertNotFound();
        $this->postJson(route('panel.mesas.cuentas.pedido.validar', $ajena), ['lineas' => [$this->lineaLomo()]])->assertNotFound();

        $this->assertSame(0, Pedido::count());
    }

    public function test_module_off_is_404(): void
    {
        $this->restaurante->forceFill(['modulos' => null])->save();

        $this->actingAs($this->mesero->fresh())->enviar()->assertNotFound();
        $this->get(route('panel.mesas.cuentas.pedido.create', $this->cuenta))->assertNotFound();
    }

    public function test_out_of_operation_rounds_are_not_sent(): void
    {
        Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'vencida', 'vence_el' => '2026-10-01']);

        $this->actingAs($this->mesero->fresh())->enviar()->assertForbidden()->assertJsonPath('message', EnsureLocalOperativo::MENSAJE);

        // El servicio también lo revisa, por si se le llama por otra vía.
        $this->expectException(ConflictHttpException::class);
        app(CrearPedidoSalon::class)->enviar($this->mesero->fresh(), $this->cuenta, [$this->lineaLomo()], Str::uuid()->toString());
    }

    public function test_the_order_screen_shows_the_menu_and_sold_out_products_cannot_be_ordered(): void
    {
        $this->lomo->update(['disponible' => false]);

        $respuesta = $this->actingAs($this->mesero)->get(route('panel.mesas.cuentas.pedido.create', $this->cuenta))
            ->assertOk()
            ->assertSee('Mesa 4 + 5')
            ->assertSee('Cuenta #1')
            ->assertSee('Chaufa')
            ->assertSee('data-buscar', false)
            ->assertSee('id="hoja-producto"', false)
            ->assertSee('window.FOORA_SALON', false)
            ->assertDontSee('FOORA_VITRINA', false);

        $this->assertMatchesRegularExpression('/data-producto="'.$this->lomo->id.'"\s+disabled/', $respuesta->getContent());
        // La carta reutiliza los parciales de la vitrina, pero no su entrada de JavaScript.
        $this->assertDoesNotMatchRegularExpression('#assets/vitrina-[^"]*\.js#', $respuesta->getContent());
    }

    public function test_the_order_screen_of_a_closed_account_goes_back_to_the_map(): void
    {
        app(GestionarCuentas::class)->cerrarExterno($this->cajero, $this->cuenta, 1, 0);

        $this->actingAs($this->mesero)->get(route('panel.mesas.cuentas.pedido.create', $this->cuenta))
            ->assertRedirect(route('panel.mesas.index'))
            ->assertSessionHas('error', GestionarCuentas::MENSAJE_YA_NO_ABIERTA);
    }

    public function test_html_in_an_observation_is_stored_as_text_and_always_escaped(): void
    {
        $ataque = '<script>alert(1)</script><img src=x onerror=alert(2)>';

        $this->actingAs($this->mesero)->enviar([$this->lineaLomo(1, $ataque)])->assertOk();
        $this->assertSame($ataque, PedidoItem::sole()->observacion);

        $pedido = Pedido::sole();
        $cocina = User::factory()->personal('cocina', $this->restaurante)->create();

        $respuestas = [
            $this->actingAs($this->mesero)->get(route('panel.mesas.cuentas.show', $this->cuenta)),
            $this->actingAs($this->cajero)->get(route('panel.pedidos.show', $pedido), ['X-Requested-With' => 'XMLHttpRequest']),
            $this->actingAs($cocina)->get(route('panel.pedidos.show', $pedido), ['X-Requested-With' => 'XMLHttpRequest']),
            $this->actingAs($this->cajero)->get(route('panel.mesas.cuentas.precuenta.imprimir', $this->cuenta)),
        ];

        foreach ($respuestas as $respuesta) {
            $respuesta->assertOk();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $respuesta->getContent());
            $this->assertStringNotContainsString('<img src=x', $respuesta->getContent());
        }

        $respuestas[0]->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
