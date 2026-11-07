<?php

namespace Tests\Feature\Vitrina;

use App\Http\Requests\PedidoRequest;
use App\Models\Alerta;
use App\Models\CategoriaProducto;
use App\Models\GrupoOpcion;
use App\Models\MetodoPago;
use App\Models\Opcion;
use App\Models\Pedido;
use App\Models\PedidoHistorial;
use App\Models\PedidoItem;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\Restaurante;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CrearPedidoTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private CategoriaProducto $categoria;

    private Producto $simple;

    private Producto $conVariantes;

    private ProductoVariante $personal;

    private Opcion $tocino;

    private MetodoPago $efectivo;

    private MetodoPago $yape;

    private MetodoPago $tarjeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->create([
            'slug' => 'polleria-central',
            'costo_delivery' => 5.50,
            'pedido_minimo' => 20,
            'tiempo_prep_min' => 35,
        ]);
        $this->categoria = CategoriaProducto::factory()->for($this->restaurante)->create();

        $this->simple = Producto::factory()->for($this->categoria, 'categoria')->create(['nombre' => 'Anticuchos', 'precio' => 18.00]);
        $this->conVariantes = Producto::factory()->for($this->categoria, 'categoria')->create(['nombre' => 'Chaufa', 'tipo' => 'variantes', 'precio' => 22.00]);
        $this->personal = ProductoVariante::factory()->for($this->conVariantes)->create(['nombre' => 'Personal', 'precio' => 22.00]);

        $adicionales = GrupoOpcion::factory()->for($this->restaurante)->multiple()->create(['nombre' => 'Adicionales']);
        $this->tocino = Opcion::factory()->for($adicionales, 'grupo')->create(['nombre' => 'Tocino', 'precio_extra' => 5.00]);
        $this->conVariantes->gruposOpcion()->attach($adicionales);

        $this->efectivo = MetodoPago::create(['codigo' => 'efectivo', 'nombre' => 'Efectivo']);
        $this->yape = MetodoPago::create(['codigo' => 'yape', 'nombre' => 'Yape', 'requiere_validacion' => true]);
        $this->tarjeta = MetodoPago::create(['codigo' => 'tarjeta', 'nombre' => 'Tarjeta (POS)']);

        $this->restaurante->metodosPago()->attach($this->efectivo, ['activo' => true]);
        $this->restaurante->metodosPago()->attach($this->yape, ['activo' => true, 'numero_cuenta' => '962345678', 'titular' => 'Rosa Quispe']);
        $this->restaurante->metodosPago()->attach($this->tarjeta, ['activo' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lineaSimple(int $cantidad = 2): array
    {
        return ['producto_id' => $this->simple->id, 'variante_id' => null, 'opciones' => [], 'cantidad' => $cantidad, 'observacion' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineaCompleta(): array
    {
        return ['producto_id' => $this->conVariantes->id, 'variante_id' => $this->personal->id, 'opciones' => [$this->tocino->id], 'cantidad' => 1, 'observacion' => 'Sin ají'];
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Ana Torres',
            'telefono' => '987654321',
            'tipo' => 'recojo',
            'direccion' => null,
            'referencia' => null,
            'metodo_pago_id' => $this->efectivo->id,
            'referencia_pago' => null,
            'acepta_privacidad' => true,
            'sitio_web' => '',
            'idempotency_key' => Str::uuid()->toString(),
            'lineas' => [$this->lineaSimple()],
        ], $cambios);
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function datosDelivery(array $cambios = []): array
    {
        return $this->datos(['tipo' => 'delivery', 'direccion' => 'Jr. Huallayco 840', 'referencia' => 'Casa azul', ...$cambios]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function pedir(array $datos, string $slug = 'polleria-central'): TestResponse
    {
        return $this->postJson(route('vitrina.pedido.store', $slug), $datos);
    }

    private function pedido(): Pedido
    {
        return Pedido::query()->firstOrFail();
    }

    public function test_delivery_order_is_stored_with_exact_totals_and_copies(): void
    {
        $this->pedir($this->datosDelivery([
            'lineas' => [$this->lineaSimple(), $this->lineaCompleta()],
            'telefono' => '+51 987-654-321',
            'nombre' => '  Ana Torres ',
        ]))
            ->assertCreated()
            ->assertJsonStructure(['codigo', 'url']);

        $pedido = $this->pedido();

        $this->assertSame(1, $pedido->numero);
        $this->assertSame($this->restaurante->id, $pedido->restaurante_id);
        $this->assertSame('Ana Torres', $pedido->nombre_cliente);
        $this->assertSame('987654321', $pedido->telefono_cliente);
        $this->assertSame('delivery', $pedido->tipo);
        $this->assertSame('app', $pedido->origen);
        $this->assertSame('Jr. Huallayco 840', $pedido->direccion_entrega);
        $this->assertSame('Casa azul', $pedido->referencia_entrega);
        $this->assertSame('63.00', $pedido->subtotal);
        $this->assertSame('5.50', $pedido->costo_delivery);
        $this->assertSame('0.00', $pedido->descuento);
        $this->assertSame('68.50', $pedido->total);
        $this->assertSame('nuevo', $pedido->estado);
        $this->assertSame('pendiente', $pedido->estado_pago);
        $this->assertSame(35, $pedido->tiempo_estimado_min);
        $this->assertNull($pedido->aceptado_at);
        $this->assertNotNull($pedido->consentimiento_at);
        $this->assertSame(Pedido::CONSENTIMIENTO_VERSION, $pedido->consentimiento_version);

        $items = PedidoItem::where('pedido_id', $pedido->id)->with('opciones')->orderBy('id')->get();

        $this->assertCount(2, $items);
        $this->assertSame('Anticuchos', $items[0]->nombre);
        $this->assertSame(2, $items[0]->cantidad);
        $this->assertSame('18.00', $items[0]->precio_unitario);
        $this->assertSame('36.00', $items[0]->subtotal);
        $this->assertSame('Chaufa', $items[1]->nombre);
        $this->assertSame('Personal', $items[1]->variante_nombre);
        $this->assertSame($this->personal->id, $items[1]->variante_id);
        $this->assertSame('27.00', $items[1]->precio_unitario);
        $this->assertSame('Sin ají', $items[1]->observacion);

        $opcion = $items[1]->opciones->sole();
        $this->assertSame($this->tocino->id, $opcion->opcion_id);
        $this->assertSame('Adicionales', $opcion->grupo_nombre);
        $this->assertSame('Tocino', $opcion->nombre);
        $this->assertSame('5.00', $opcion->precio_extra);

        $historial = PedidoHistorial::where('pedido_id', $pedido->id)->sole();
        $this->assertNull($historial->estado_anterior);
        $this->assertSame('nuevo', $historial->estado_nuevo);

        $alerta = Alerta::where('restaurante_id', $this->restaurante->id)->sole();
        $this->assertSame('pedido_nuevo', $alerta->tipo);
        $this->assertSame('media', $alerta->prioridad);
        $this->assertSame('pedido', $alerta->referencia_tipo);
        $this->assertSame($pedido->id, $alerta->referencia_id);
    }

    public function test_pickup_order_has_no_delivery_cost_or_address(): void
    {
        $this->pedir($this->datos(['direccion' => 'No debe guardarse', 'referencia' => 'Tampoco']))->assertCreated();

        $pedido = $this->pedido();

        $this->assertSame('36.00', $pedido->subtotal);
        $this->assertSame('0.00', $pedido->costo_delivery);
        $this->assertSame('36.00', $pedido->total);
        $this->assertNull($pedido->direccion_entrega);
        $this->assertNull($pedido->referencia_entrega);
    }

    public function test_yape_order_is_pending_verification_with_urgent_alert(): void
    {
        $this->pedir($this->datos(['metodo_pago_id' => $this->yape->id, 'referencia_pago' => 'OP-123']))->assertCreated();

        $pedido = $this->pedido();

        $this->assertSame('por_verificar', $pedido->estado_pago);
        $this->assertSame('OP-123', $pedido->referencia_pago);

        $alertas = Alerta::orderBy('id')->get();
        $this->assertSame(['pago_pendiente', 'pedido_nuevo'], $alertas->pluck('tipo')->all());
        $this->assertSame('urgente', $alertas[0]->prioridad);
    }

    public function test_operation_code_is_ignored_for_cash(): void
    {
        $this->pedir($this->datos(['referencia_pago' => 'OP-123']))->assertCreated();

        $this->assertNull($this->pedido()->referencia_pago);
        $this->assertSame('pendiente', $this->pedido()->estado_pago);
    }

    public function test_auto_accept_sends_cash_orders_straight_to_preparation(): void
    {
        $this->restaurante->update(['acepta_pedidos_auto' => true]);

        $this->pedir($this->datos())->assertCreated();

        $pedido = $this->pedido();
        $this->assertSame('en_preparacion', $pedido->estado);
        $this->assertNotNull($pedido->aceptado_at);
        $this->assertSame('en_preparacion', PedidoHistorial::where('pedido_id', $pedido->id)->sole()->estado_nuevo);
        $this->assertDatabaseCount('alertas', 0);
    }

    public function test_without_auto_accept_the_order_stays_new(): void
    {
        $this->pedir($this->datos())->assertCreated();

        $this->assertSame('nuevo', $this->pedido()->estado);
        $this->assertNull($this->pedido()->aceptado_at);
    }

    public function test_auto_accept_never_applies_to_a_payment_to_verify(): void
    {
        $this->restaurante->update(['acepta_pedidos_auto' => true]);

        $this->pedir($this->datos(['metodo_pago_id' => $this->yape->id]))->assertCreated();

        $pedido = $this->pedido();
        $this->assertSame('nuevo', $pedido->estado);
        $this->assertNull($pedido->aceptado_at);
        $this->assertSame('por_verificar', $pedido->estado_pago);
        $this->assertSame(['pago_pendiente', 'pedido_nuevo'], Alerta::orderBy('id')->pluck('tipo')->all());
        $this->assertSame('urgente', Alerta::where('tipo', 'pago_pendiente')->value('prioridad'));
    }

    public function test_server_ignores_manipulated_prices(): void
    {
        $linea = [...$this->lineaCompleta(), 'precio' => 0.01, 'precio_unitario' => 0.01, 'subtotal' => 0.01, 'precio_extra' => 0];

        $this->pedir($this->datosDelivery([
            'lineas' => [$linea],
            'subtotal' => 0.01,
            'total' => 0.01,
            'costo_delivery' => 0,
            'descuento' => 500,
            'estado' => 'entregado',
            'estado_pago' => 'pagado',
            'numero' => 999,
        ]))->assertCreated();

        $pedido = $this->pedido();

        $this->assertSame('27.00', $pedido->subtotal);
        $this->assertSame('5.50', $pedido->costo_delivery);
        $this->assertSame('32.50', $pedido->total);
        $this->assertSame('0.00', $pedido->descuento);
        $this->assertSame('nuevo', $pedido->estado);
        $this->assertSame('pendiente', $pedido->estado_pago);
        $this->assertSame(1, $pedido->numero);
        $this->assertSame('27.00', PedidoItem::sole()->precio_unitario);
    }

    public function test_closed_restaurant_is_rejected(): void
    {
        $this->restaurante->update(['estado_local' => 'cerrado']);

        $this->pedir($this->datos())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['restaurante' => 'está cerrado ahora']);

        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_restaurant_outside_its_schedule_is_rejected(): void
    {
        $this->restaurante->horariosAtencion()->create(['dia_semana' => 1, 'abre' => '12:00', 'cierra' => '22:00']);
        Carbon::setTestNow(Carbon::parse('2026-11-02 23:00', 'America/Lima'));

        $this->pedir($this->datos())->assertUnprocessable()->assertJsonValidationErrors('restaurante');

        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_order_type_not_accepted_is_rejected(): void
    {
        $this->restaurante->update(['acepta_delivery' => false]);

        $this->pedir($this->datosDelivery())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tipo' => 'no hace delivery']);

        $this->restaurante->update(['acepta_delivery' => true, 'acepta_recojo' => false]);

        $this->pedir($this->datos())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tipo' => 'no atiende recojo']);

        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_subtotal_below_the_minimum_is_rejected_even_if_delivery_would_reach_it(): void
    {
        $this->pedir($this->datosDelivery(['lineas' => [$this->lineaSimple(1)]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lineas' => 'El pedido mínimo en este local es S/ 20.00.']);

        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_inactive_or_foreign_payment_method_is_rejected(): void
    {
        $otro = MetodoPago::create(['codigo' => 'plin', 'nombre' => 'Plin', 'requiere_validacion' => true]);

        foreach ([$this->tarjeta->id, $otro->id, 999999] as $metodo) {
            $this->pedir($this->datos(['metodo_pago_id' => $metodo]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['metodo_pago_id' => 'no está disponible']);
        }
    }

    public function test_yape_without_account_number_is_rejected(): void
    {
        $this->restaurante->metodosPago()->updateExistingPivot($this->yape->id, ['numero_cuenta' => null]);

        $this->pedir($this->datos(['metodo_pago_id' => $this->yape->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metodo_pago_id');
    }

    public function test_lines_from_another_restaurant_are_rejected(): void
    {
        $otro = Restaurante::factory()->create();
        $ajeno = Producto::factory()->for(CategoriaProducto::factory()->for($otro), 'categoria')->create(['precio' => 50]);

        $this->pedir($this->datos(['lineas' => [
            $this->lineaSimple(),
            ['producto_id' => $ajeno->id, 'variante_id' => null, 'opciones' => [], 'cantidad' => 1],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lineas.1' => 'Este producto ya no está en la carta.']);

        $this->assertDatabaseCount('pedidos', 0);
        $this->assertDatabaseCount('pedido_items', 0);
    }

    public function test_sold_out_line_is_rejected_and_nothing_is_saved(): void
    {
        $this->simple->update(['disponible' => false]);

        $this->pedir($this->datos())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lineas.0' => 'no está disponible']);

        $this->assertDatabaseCount('pedidos', 0);
        $this->assertDatabaseCount('alertas', 0);
    }

    public function test_empty_or_oversized_cart_is_rejected(): void
    {
        $this->pedir($this->datos(['lineas' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lineas' => 'Tu carrito está vacío.']);

        $this->pedir($this->datos(['lineas' => array_fill(0, 31, $this->lineaSimple())]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lineas' => 'hasta 30']);
    }

    public function test_long_observation_is_rejected(): void
    {
        $this->pedir($this->datos(['lineas' => [[...$this->lineaSimple(), 'observacion' => str_repeat('a', 201)]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lineas.0.observacion' => 'hasta 200']);

        $this->pedir($this->datos(['lineas' => [[...$this->lineaSimple(), 'observacion' => str_repeat('a', 200)]]]))
            ->assertCreated();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function telefonosInvalidos(): array
    {
        return [
            'corto' => ['98765432'],
            'largo' => ['9876543210'],
            'no empieza con 9' => ['887654321'],
            'letras' => ['abcdefghi'],
            'vacío' => [''],
            'otro país' => ['+52 987654321'],
        ];
    }

    #[DataProvider('telefonosInvalidos')]
    public function test_invalid_phone_is_rejected(string $telefono): void
    {
        $this->pedir($this->datos(['telefono' => $telefono]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('telefono');
    }

    public function test_required_fields_are_validated_per_field(): void
    {
        $this->pedir($this->datos(['nombre' => '', 'acepta_privacidad' => false, 'metodo_pago_id' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'acepta_privacidad', 'metodo_pago_id']);

        $this->pedir($this->datos(['tipo' => 'delivery', 'direccion' => ' ', 'referencia' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['direccion', 'referencia']);

        $this->pedir($this->datos(['tipo' => 'salon']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tipo');
    }

    public function test_same_idempotency_key_returns_the_same_order_without_error(): void
    {
        $datos = $this->datos();

        $primera = $this->pedir($datos)->assertCreated();
        $segunda = $this->pedir($datos)->assertOk();

        $this->assertSame($primera->json('codigo'), $segunda->json('codigo'));
        $this->assertSame($primera->json('url'), $segunda->json('url'));
        $this->assertDatabaseCount('pedidos', 1);
        $this->assertDatabaseCount('pedido_items', 1);
        $this->assertDatabaseCount('alertas', 1);
        $this->assertDatabaseCount('pedido_historial', 1);
    }

    public function test_repeated_key_succeeds_even_if_the_restaurant_closed_meanwhile(): void
    {
        $datos = $this->datos();

        $primera = $this->pedir($datos)->assertCreated();
        $this->restaurante->update(['estado_local' => 'cerrado']);

        $this->pedir($datos)->assertOk()->assertJsonPath('codigo', $primera->json('codigo'));
    }

    public function test_same_key_in_another_restaurant_is_a_different_order(): void
    {
        $otro = Restaurante::factory()->create(['slug' => 'otro-local']);
        $categoria = CategoriaProducto::factory()->for($otro)->create();
        $producto = Producto::factory()->for($categoria, 'categoria')->create(['precio' => 30]);
        $otro->metodosPago()->attach($this->efectivo, ['activo' => true]);

        $clave = Str::uuid()->toString();

        $this->pedir($this->datos(['idempotency_key' => $clave]))->assertCreated();
        $this->pedir($this->datos([
            'idempotency_key' => $clave,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1, 'opciones' => []]],
        ]), 'otro-local')->assertCreated();

        $this->assertDatabaseCount('pedidos', 2);
    }

    public function test_numbering_is_consecutive_and_per_restaurant(): void
    {
        $otro = Restaurante::factory()->create(['slug' => 'otro-local']);
        $producto = Producto::factory()->for(CategoriaProducto::factory()->for($otro), 'categoria')->create(['precio' => 30]);
        $otro->metodosPago()->attach($this->efectivo, ['activo' => true]);

        $this->pedir($this->datos(['telefono' => '911111111']))->assertCreated();
        $this->pedir($this->datos(['telefono' => '922222222']))->assertCreated();
        $this->pedir($this->datos(['telefono' => '933333333']))->assertCreated();
        $this->pedir($this->datos(['lineas' => [['producto_id' => $producto->id, 'cantidad' => 1, 'opciones' => []]]]), 'otro-local')->assertCreated();

        $this->assertSame([1, 2, 3], Pedido::where('restaurante_id', $this->restaurante->id)->orderBy('id')->pluck('numero')->all());
        $this->assertSame([1], Pedido::where('restaurante_id', $otro->id)->pluck('numero')->all());
    }

    public function test_throttle_allows_five_orders_per_minute_and_answers_kindly(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pedir($this->datos(['nombre' => '', 'telefono' => '987654321']))->assertUnprocessable();
        }

        $this->pedir($this->datos())
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Se enviaron muchos pedidos desde tu conexión. Espera un momento e inténtalo otra vez.');

        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_throttle_has_an_hourly_limit_of_thirty(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->travel(61)->seconds();
            $this->pedir($this->datos(['nombre' => '']))->assertUnprocessable();
        }

        $this->travel(61)->seconds();
        $this->pedir($this->datos())->assertTooManyRequests();
    }

    public function test_a_phone_can_have_at_most_three_active_orders_per_restaurant(): void
    {
        Pedido::factory()->count(3)->sequence(fn ($s) => ['numero' => $s->index + 1])->create([
            'restaurante_id' => $this->restaurante->id,
            'telefono_cliente' => '987654321',
        ]);

        $this->pedir($this->datos())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['telefono' => 'Ya tienes 3 pedidos en curso']);

        $this->assertDatabaseCount('pedidos', 3);
    }

    public function test_finished_orders_and_other_restaurants_do_not_count_for_the_phone_limit(): void
    {
        $otro = Restaurante::factory()->create();

        Pedido::factory()->count(5)->sequence(fn ($s) => ['numero' => $s->index + 1, 'estado' => $s->index % 2 ? 'entregado' : 'cancelado'])->create([
            'restaurante_id' => $this->restaurante->id,
            'telefono_cliente' => '987654321',
        ]);
        Pedido::factory()->count(3)->sequence(fn ($s) => ['numero' => $s->index + 1])->create([
            'restaurante_id' => $otro->id,
            'telefono_cliente' => '987654321',
        ]);

        $this->pedir($this->datos())->assertCreated();

        $this->assertSame(6, $this->pedidoNumeroMaximo());
    }

    private function pedidoNumeroMaximo(): int
    {
        return (int) Pedido::where('restaurante_id', $this->restaurante->id)->max('numero');
    }

    public function test_honeypot_answers_like_a_success_and_creates_nothing(): void
    {
        $respuesta = $this->pedir($this->datos([PedidoRequest::CAMPO_TRAMPA => 'https://spam.test']))
            ->assertCreated()
            ->assertJsonStructure(['codigo', 'url']);

        $this->assertDatabaseCount('pedidos', 0);
        $this->assertDatabaseCount('alertas', 0);

        $this->get($respuesta->json('url'))->assertNotFound();
    }

    public function test_honeypot_also_wins_over_validation(): void
    {
        $this->pedir([PedidoRequest::CAMPO_TRAMPA => 'bot'])->assertCreated();

        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_only_json_requests_are_accepted(): void
    {
        $this->post(route('vitrina.pedido.store', 'polleria-central'), $this->datos(), ['Accept' => 'application/json'])
            ->assertUnsupportedMediaType();

        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_hidden_or_missing_restaurant_is_not_found(): void
    {
        Restaurante::factory()->create(['slug' => 'oculto', 'visible_en_app' => false]);

        $this->pedir($this->datos(), 'oculto')->assertNotFound();
        $this->pedir($this->datos(), 'no-existe')->assertNotFound();
    }

    public function test_logged_in_admin_of_another_restaurant_orders_against_this_restaurant(): void
    {
        $otro = Restaurante::factory()->create();
        Pedido::factory()->create(['restaurante_id' => $this->restaurante->id, 'numero' => 7, 'estado' => 'entregado']);

        $this->actingAs(User::factory()->admin($otro)->create());

        $this->assertSame(0, Pedido::count());

        $this->pedir($this->datos())->assertCreated();

        $this->actingAs(User::factory()->admin($this->restaurante)->create());
        $this->assertSame([7, 8], Pedido::orderBy('numero')->pluck('numero')->all());
    }

    public function test_tracking_code_is_random_long_unique_and_hidden_from_serialization(): void
    {
        $this->pedir($this->datos(['telefono' => '911111111']))->assertCreated();
        $this->pedir($this->datos(['telefono' => '922222222']))->assertCreated();

        $codigos = Pedido::pluck('codigo_seguimiento');

        $this->assertSame(Pedido::LONGITUD_CODIGO, strlen($codigos[0]));
        $this->assertGreaterThanOrEqual(20, strlen($codigos[0]));
        $this->assertNotSame($codigos[0], $codigos[1]);
        $this->assertArrayNotHasKey('codigo_seguimiento', $this->pedido()->toArray());
        $this->assertArrayNotHasKey('idempotency_key', $this->pedido()->toArray());
    }

    public function test_html_in_name_address_and_observations_is_stored_as_text_and_escaped_in_tracking(): void
    {
        $ataque = '</script><script>alert(1)</script>';

        $url = $this->pedir($this->datosDelivery([
            'nombre' => "{$ataque} Torres",
            'direccion' => "Jr. Lima 1 {$ataque}",
            'referencia' => "<img src=x onerror=alert(2)> {$ataque}",
            'lineas' => [[...$this->lineaSimple(), 'observacion' => $ataque]],
        ]))->assertCreated()->json('url');

        $pedido = $this->pedido();

        $this->assertSame("{$ataque} Torres", $pedido->nombre_cliente);
        $this->assertSame("Jr. Lima 1 {$ataque}", $pedido->direccion_entrega);
        $this->assertSame($ataque, PedidoItem::sole()->observacion);

        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('Jr. Lima', $html);
        $this->assertStringContainsString('&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_vitrina_offers_only_accepted_types_and_available_methods(): void
    {
        $this->restaurante->update(['acepta_delivery' => false]);

        $this->get(route('vitrina.show', 'polleria-central'))
            ->assertSee('Recojo en local')
            ->assertDontSee('value="delivery"', false)
            ->assertSee('962345678')
            ->assertSee('Rosa Quispe')
            ->assertDontSee('Tarjeta (POS)')
            ->assertSee('Ley N.° 29733')
            ->assertSee(PedidoRequest::CAMPO_TRAMPA);
    }
}
