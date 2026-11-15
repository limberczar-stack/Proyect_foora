<?php

namespace Tests\Feature\Mesas;

use App\Exceptions\RondasSinServir;
use App\Models\Alerta;
use App\Models\Area;
use App\Models\CategoriaProducto;
use App\Models\Cuenta;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\PedidoHistorial;
use App\Models\PedidoItem;
use App\Models\Producto;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\User;
use App\Services\CrearPedidoSalon;
use App\Services\GestionarCuentas;
use App\Services\GestionarPedidoSalon;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Después de enviar las rondas: servir, anular líneas, cancelar rondas, precuenta, cerrar, anular la
 * cuenta y fusionar. También lo que cuentan las ventas del día y lo que pinta el plano.
 */
class GestionRondasTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    private User $cajero;

    private User $mesero;

    private User $cocina;

    private Producto $lomo;

    private Producto $chicha;

    /** @var list<Mesa> */
    private array $mesas;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 13:00', 'America/Lima'));

        $this->restaurante = Restaurante::factory()->conModulos('mesas')->create();
        $this->admin = User::factory()->admin($this->restaurante)->create();
        $this->cajero = User::factory()->cajero($this->restaurante)->create(['name' => 'Miguel Torres']);
        $this->mesero = User::factory()->personal('mesero', $this->restaurante)->create(['name' => 'Luis Chávez']);
        $this->cocina = User::factory()->personal('cocina', $this->restaurante)->create();

        $categoria = CategoriaProducto::factory()->for($this->restaurante)->create();
        $this->lomo = Producto::factory()->for($categoria, 'categoria')->create(['nombre' => 'Lomo saltado', 'precio' => 36.50]);
        $this->chicha = Producto::factory()->for($categoria, 'categoria')->create(['nombre' => 'Chicha morada', 'precio' => 8.00]);

        $area = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Salón']);
        $this->mesas = collect(range(1, 8))->map(fn (int $n): Mesa => Mesa::factory()->for($area)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => (string) $n, 'orden' => $n]))->all();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<int>  $mesas  posiciones en $this->mesas
     */
    private function cuenta(array $mesas = [0], ?User $quien = null): Cuenta
    {
        return app(GestionarCuentas::class)->abrir($quien ?? $this->mesero, array_map(fn (int $i): int => $this->mesas[$i]->id, $mesas), 2);
    }

    /**
     * Una ronda real: lomo × $lomos (36.50 c/u) y chicha × $chichas (8.00 c/u).
     */
    private function ronda(Cuenta $cuenta, int $lomos = 2, int $chichas = 1, ?User $quien = null): Pedido
    {
        $lineas = array_values(array_filter([
            $lomos > 0 ? ['producto_id' => $this->lomo->id, 'variante_id' => null, 'opciones' => [], 'cantidad' => $lomos, 'observacion' => null] : null,
            $chichas > 0 ? ['producto_id' => $this->chicha->id, 'variante_id' => null, 'opciones' => [], 'cantidad' => $chichas, 'observacion' => null] : null,
        ]));

        return app(CrearPedidoSalon::class)->enviar($quien ?? $this->mesero, $cuenta, $lineas, Str::uuid()->toString());
    }

    private function listo(Pedido $pedido): Pedido
    {
        $this->actingAs($this->cocina)->patchJson(route('panel.pedidos.estado', $pedido), ['desde' => 'en_preparacion', 'destino' => 'listo'])->assertOk();

        return $pedido->fresh();
    }

    private function total(Cuenta $cuenta): int
    {
        return GestionarCuentas::totalCentimos($cuenta);
    }

    /* ---------------------------------------------------------------------- */
    /* Servir */
    /* ---------------------------------------------------------------------- */

    public function test_the_kitchen_marks_ready_and_the_waiter_serves(): void
    {
        $cuenta = $this->cuenta();
        $pedido = $this->listo($this->ronda($cuenta));

        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.pedidos.servir', [$cuenta, $pedido]))
            ->assertOk()->assertJsonPath('mensaje', 'Ronda 1 servida.');

        $pedido->refresh();
        $this->assertSame(['entregado', 'pendiente'], [$pedido->estado, $pedido->estado_pago]);
        $this->assertNotNull($pedido->entregado_at);
    }

    public function test_only_a_ready_round_is_served(): void
    {
        $cuenta = $this->cuenta();
        $pedido = $this->ronda($cuenta);

        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.pedidos.servir', [$cuenta, $pedido]))->assertConflict();
        $this->assertSame('en_preparacion', $pedido->fresh()->estado);
    }

    public function test_the_waiter_cannot_mark_ready_and_the_kitchen_cannot_serve(): void
    {
        $cuenta = $this->cuenta();
        $pedido = $this->ronda($cuenta);

        $this->actingAs($this->mesero)->patchJson(route('panel.pedidos.estado', $pedido), ['desde' => 'en_preparacion', 'destino' => 'listo'])->assertForbidden();

        $this->listo($pedido);
        $this->actingAs($this->cocina)->patchJson(route('panel.mesas.cuentas.pedidos.servir', [$cuenta, $pedido]))->assertForbidden();
        $this->actingAs($this->cocina)->patchJson(route('panel.pedidos.estado', $pedido), ['desde' => 'listo', 'destino' => 'entregado'])->assertForbidden();

        $this->assertSame('listo', $pedido->fresh()->estado);
    }

    public function test_a_salon_round_never_goes_on_the_way(): void
    {
        $pedido = $this->listo($this->ronda($this->cuenta()));

        $this->actingAs($this->cajero)->patchJson(route('panel.pedidos.estado', $pedido), ['desde' => 'listo', 'destino' => 'en_camino'])->assertUnprocessable();
        $this->assertSame('listo', $pedido->fresh()->estado);
    }

    public function test_a_round_of_another_account_cannot_be_served_through_this_one(): void
    {
        $cuenta = $this->cuenta([0]);
        $otra = $this->cuenta([1]);
        $pedido = $this->listo($this->ronda($otra));

        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.pedidos.servir', [$cuenta, $pedido]))->assertConflict();
        $this->assertSame('listo', $pedido->fresh()->estado);
    }

    /* ---------------------------------------------------------------------- */
    /* Anular una línea */
    /* ---------------------------------------------------------------------- */

    public function test_voiding_a_line_marks_it_and_recalculates_the_round(): void
    {
        $cuenta = $this->cuenta();
        $pedido = $this->ronda($cuenta, 2, 1);
        $chicha = PedidoItem::where('nombre', 'Chicha morada')->sole();

        $this->actingAs($this->cajero)->patchJson(route('panel.mesas.cuentas.lineas.anular', [$cuenta, $chicha->id]), ['motivo' => 'El cliente la cambió'])
            ->assertOk()->assertJsonPath('mensaje', 'Se anuló la línea de la ronda 1.');

        $chicha->refresh();
        $this->assertNotNull($chicha->anulado_at);
        $this->assertSame([$this->cajero->id, 'El cliente la cambió'], [$chicha->anulado_por, $chicha->motivo_anulacion]);
        $this->assertModelExists($chicha);
        $this->assertSame(['73.00', '73.00', 'en_preparacion'], [$pedido->fresh()->subtotal, $pedido->fresh()->total, $pedido->fresh()->estado]);
        $this->assertSame(7300, $this->total($cuenta));
        $this->assertStringContainsString('Línea anulada (1 × Chicha morada). Motivo: El cliente la cambió', PedidoHistorial::where('pedido_id', $pedido->id)->latest('id')->first()->nota);
    }

    public function test_voiding_every_line_cancels_the_round(): void
    {
        $cuenta = $this->cuenta();
        $pedido = $this->ronda($cuenta, 1, 1);
        $this->actingAs($this->admin);

        foreach (PedidoItem::orderBy('id')->get() as $item) {
            $this->patchJson(route('panel.mesas.cuentas.lineas.anular', [$cuenta, $item->id]), ['motivo' => 'Se equivocó de mesa'])->assertOk();
        }

        $pedido->refresh();
        $this->assertSame(['cancelado', '0.00', 'otro'], [$pedido->estado, $pedido->total, $pedido->cancelacion_motivo]);
        $this->assertSame(0, $this->total($cuenta));
        $this->assertSame(2, PedidoItem::count(), 'Nada se borra.');
    }

    public function test_only_admin_and_cashier_void_lines_and_a_reason_is_required(): void
    {
        $cuenta = $this->cuenta();
        $this->ronda($cuenta);
        $item = PedidoItem::first();

        foreach ([$this->mesero, $this->cocina] as $usuario) {
            $this->actingAs($usuario)->patchJson(route('panel.mesas.cuentas.lineas.anular', [$cuenta, $item->id]), ['motivo' => 'Porque sí'])->assertForbidden();
        }

        $this->actingAs($this->cajero)->patchJson(route('panel.mesas.cuentas.lineas.anular', [$cuenta, $item->id]), [])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->patchJson(route('panel.mesas.cuentas.lineas.anular', [$cuenta, $item->id]), ['motivo' => 'ab'])->assertUnprocessable();

        $this->assertNull($item->fresh()->anulado_at);
    }

    public function test_a_line_already_voided_gives_409_and_a_line_of_another_account_is_404(): void
    {
        $cuenta = $this->cuenta([0]);
        $otra = $this->cuenta([1]);
        $this->ronda($cuenta);
        $this->ronda($otra);
        $propia = PedidoItem::whereHas('pedido', fn ($q) => $q->where('cuenta_id', $cuenta->id))->first();
        $ajena = PedidoItem::whereHas('pedido', fn ($q) => $q->where('cuenta_id', $otra->id))->first();
        $this->actingAs($this->cajero);

        $this->patchJson(route('panel.mesas.cuentas.lineas.anular', [$cuenta, $propia->id]), ['motivo' => 'Duplicado'])->assertOk();
        $this->patchJson(route('panel.mesas.cuentas.lineas.anular', [$cuenta, $propia->id]), ['motivo' => 'Duplicado'])->assertConflict();
        $this->patchJson(route('panel.mesas.cuentas.lineas.anular', [$cuenta, $ajena->id]), ['motivo' => 'Duplicado'])->assertNotFound();

        $this->assertNull($ajena->fresh()->anulado_at);
    }

    /* ---------------------------------------------------------------------- */
    /* Cancelar una ronda */
    /* ---------------------------------------------------------------------- */

    public function test_admin_or_cashier_cancel_a_whole_round_with_a_reason(): void
    {
        $cuenta = $this->cuenta();
        $pedido = $this->ronda($cuenta);

        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.pedidos.cancelar', [$cuenta, $pedido]), ['desde' => 'en_preparacion', 'motivo' => 'Se fueron'])->assertForbidden();
        $this->actingAs($this->cajero)->patchJson(route('panel.mesas.cuentas.pedidos.cancelar', [$cuenta, $pedido]), ['desde' => 'en_preparacion'])->assertUnprocessable()->assertJsonValidationErrors('motivo');

        $this->patchJson(route('panel.mesas.cuentas.pedidos.cancelar', [$cuenta, $pedido]), ['desde' => 'en_preparacion', 'motivo' => 'Se fueron antes'])
            ->assertOk()->assertJsonPath('mensaje', 'Se canceló la ronda 1.');

        $pedido->refresh();
        $this->assertSame(['cancelado', 'otro', 'Se fueron antes'], [$pedido->estado, $pedido->cancelacion_motivo, $pedido->cancelacion_detalle]);
        $this->assertSame(0, $this->total($cuenta));
    }

    public function test_a_served_round_is_not_cancelled_whole(): void
    {
        $cuenta = $this->cuenta();
        $pedido = $this->listo($this->ronda($cuenta));
        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.pedidos.servir', [$cuenta, $pedido]))->assertOk();

        $this->actingAs($this->cajero)->patchJson(route('panel.mesas.cuentas.pedidos.cancelar', [$cuenta, $pedido]), ['desde' => 'listo', 'motivo' => 'Tarde'])->assertConflict();
        $this->assertSame('entregado', $pedido->fresh()->estado);
    }

    /* ---------------------------------------------------------------------- */
    /* Precuenta */
    /* ---------------------------------------------------------------------- */

    public function test_the_bill_request_alerts_the_cashier_and_prints_as_not_a_receipt(): void
    {
        $cuenta = $this->cuenta([0, 1]);
        $this->ronda($cuenta, 2, 1);

        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.precuenta', $cuenta), ['version' => 1])
            ->assertOk()
            ->assertJsonPath('imprimir', route('panel.mesas.cuentas.precuenta.imprimir', $cuenta));

        $cuenta->refresh();
        $this->assertSame(['precuenta', 2], [$cuenta->estado, $cuenta->version]);
        $this->assertNotNull($cuenta->precuenta_at);

        $alerta = Alerta::sole();
        $this->assertSame(['precuenta_pedida', 'cuenta', $cuenta->id, 'Precuenta pedida: Mesa 1 + 2'], [$alerta->tipo, $alerta->referencia_tipo, $alerta->referencia_id, $alerta->titulo]);
        $this->assertStringContainsString('S/ 81.00', $alerta->mensaje);

        $this->actingAs($this->cajero)->get(route('panel.mesas.cuentas.precuenta.imprimir', $cuenta))
            ->assertOk()
            ->assertSee('Precuenta. No es comprobante de pago')
            ->assertSee('@page { size: 80mm auto', false)
            ->assertSee('2 × Lomo saltado')
            ->assertSee('81.00')
            ->assertDontSee('<x-', false);

        $this->actingAs($this->cajero)->get(route('panel.inicio'))->assertOk()->assertSee('Precuenta pedida: Mesa 1 + 2');
    }

    public function test_asking_for_the_bill_twice_does_not_duplicate_and_needs_something_to_charge(): void
    {
        $vacia = $this->cuenta([2]);
        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.precuenta', $vacia), ['version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('cuenta');

        $cuenta = $this->cuenta([0]);
        $this->ronda($cuenta);
        $this->patchJson(route('panel.mesas.cuentas.precuenta', $cuenta), ['version' => 1])->assertOk();
        $this->patchJson(route('panel.mesas.cuentas.precuenta', $cuenta), ['version' => 2])->assertOk();
        $this->patchJson(route('panel.mesas.cuentas.precuenta', $cuenta), ['version' => 1])->assertConflict();

        $this->assertSame(1, Alerta::count());
        $this->assertSame(2, $cuenta->fresh()->version);
    }

    public function test_floor_roles_ask_for_the_bill_and_the_kitchen_cannot(): void
    {
        $cuenta = $this->cuenta();
        $this->ronda($cuenta);

        $this->actingAs($this->cocina)->patchJson(route('panel.mesas.cuentas.precuenta', $cuenta), ['version' => 1])->assertForbidden();
        $this->actingAs($this->cocina)->get(route('panel.mesas.cuentas.precuenta.imprimir', $cuenta))->assertForbidden();
        $this->actingAs($this->cajero)->patchJson(route('panel.mesas.cuentas.precuenta', $cuenta), ['version' => 1])->assertOk();
    }

    /* ---------------------------------------------------------------------- */
    /* Cerrar */
    /* ---------------------------------------------------------------------- */

    public function test_closing_needs_the_total_the_user_saw(): void
    {
        $cuenta = $this->cuenta();
        $pedido = $this->listo($this->ronda($cuenta, 2, 1));
        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.pedidos.servir', [$cuenta, $pedido]))->assertOk();

        $this->actingAs($this->cajero);
        $this->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('total_centimos');
        $this->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => 7300])
            ->assertConflict()->assertJsonPath('message', GestionarCuentas::MENSAJE_TOTAL_CAMBIO);
        $this->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => 7300, 'confirmar' => 1])
            ->assertConflict()->assertJsonPath('message', GestionarCuentas::MENSAJE_TOTAL_CAMBIO);
        $this->assertSame('abierta', $cuenta->fresh()->estado);

        $this->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => 8100])->assertOk();

        $this->assertSame('cerrada', $cuenta->fresh()->estado);
        $this->assertSame(['entregado', 'pagado', $this->cajero->id], [$pedido->fresh()->estado, $pedido->fresh()->estado_pago, $pedido->fresh()->pago_validado_por]);
    }

    public function test_a_line_voided_after_loading_the_screen_changes_the_total_and_blocks_the_close(): void
    {
        $cuenta = $this->cuenta();
        $this->ronda($cuenta, 2, 1);
        $visto = $this->total($cuenta);

        app(GestionarPedidoSalon::class)->anularLinea($this->admin, $cuenta, PedidoItem::where('nombre', 'Chicha morada')->sole(), 'No había');

        $this->actingAs($this->cajero)->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => $visto, 'confirmar' => 1])->assertConflict();
        $this->assertSame('abierta', $cuenta->fresh()->estado);
    }

    public function test_closing_with_unserved_rounds_lists_them_until_confirmed(): void
    {
        $cuenta = $this->cuenta();
        $primera = $this->ronda($cuenta, 1, 0);
        $segunda = $this->listo($this->ronda($cuenta, 0, 2));
        $cancelada = $this->ronda($cuenta, 1, 0);
        app(GestionarPedidoSalon::class)->cancelarRonda($this->cajero, $cuenta, $cancelada, 'en_preparacion', 'Se arrepintieron');
        $total = $this->total($cuenta);
        $this->assertSame(3650 + 1600, $total);

        $this->actingAs($this->cajero)->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => $total])
            ->assertConflict()
            ->assertJsonPath('requiere_confirmacion', true)
            ->assertJsonPath('message', 'Hay 2 rondas sin servir. Confirma que ya se entregaron para cerrar la cuenta.')
            ->assertJsonPath('rondas.0.ronda', 1)
            ->assertJsonPath('rondas.1.ronda', 2)
            ->assertJsonPath('rondas.1.estado', 'listo')
            ->assertJsonCount(2, 'rondas');
        $this->assertSame('abierta', $cuenta->fresh()->estado);

        $this->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => $total, 'confirmar' => 1])->assertOk();

        foreach ([$primera, $segunda] as $pedido) {
            $this->assertSame(['entregado', 'pagado'], [$pedido->fresh()->estado, $pedido->fresh()->estado_pago]);
            $this->assertSame('Servido al cerrar la cuenta.', PedidoHistorial::where('pedido_id', $pedido->id)->latest('id')->first()->nota);
        }

        $this->assertSame(['cancelado', 'pendiente'], [$cancelada->fresh()->estado, $cancelada->fresh()->estado_pago], 'Lo cancelado no se cobra.');
        $this->assertSame([], GestionarCuentas::mesasInconsistentes($this->restaurante->id));
    }

    public function test_without_javascript_unserved_rounds_go_back_to_the_detail_with_the_checkbox(): void
    {
        $cuenta = $this->cuenta();
        $this->ronda($cuenta);
        $total = $this->total($cuenta);

        $this->actingAs($this->cajero)->patch(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => $total])
            ->assertRedirect(route('panel.mesas.cuentas.show', $cuenta))
            ->assertSessionHas('error', 'Hay 1 ronda sin servir. Confirma que ya se entregó para cerrar la cuenta.');

        $this->get(route('panel.mesas.cuentas.show', $cuenta))
            ->assertOk()->assertSee('name="confirmar"', false)->assertSee('La ronda 1 aún no se sirvió.')->assertSee('value="'.$total.'"', false);
    }

    public function test_closing_resolves_the_bill_alert(): void
    {
        $cuenta = $this->cuenta();
        $this->ronda($cuenta);
        app(GestionarPedidoSalon::class)->pedirPrecuenta($this->mesero, $cuenta, 1);

        app(GestionarCuentas::class)->cerrarExterno($this->cajero, $cuenta, 2, $this->total($cuenta), true);

        $this->assertNotNull(Alerta::sole()->resuelta_at);
    }

    /* ---------------------------------------------------------------------- */
    /* Anular la cuenta */
    /* ---------------------------------------------------------------------- */

    public function test_voiding_an_account_with_orders_cancels_them_all_and_logs_each_one(): void
    {
        $cuenta = $this->cuenta();
        $servida = $this->listo($this->ronda($cuenta));
        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.pedidos.servir', [$cuenta, $servida]))->assertOk();
        $enCocina = $this->ronda($cuenta);

        $this->actingAs($this->cajero)->patchJson(route('panel.mesas.cuentas.anular', $cuenta), ['version' => 1, 'motivo' => 'Se fueron sin pagar'])->assertOk();

        foreach ([$servida, $enCocina] as $pedido) {
            $pedido->refresh();
            $this->assertSame(['cancelado', 'otro', 'Cuenta anulada: Se fueron sin pagar'], [$pedido->estado, $pedido->cancelacion_motivo, $pedido->cancelacion_detalle]);

            $registro = PedidoHistorial::where('pedido_id', $pedido->id)->latest('id')->first();
            $this->assertSame(['cancelado', $this->cajero->id, 'Cuenta #1 anulada. Motivo: Se fueron sin pagar'], [$registro->estado_nuevo, $registro->user_id, $registro->nota]);
        }

        $this->assertSame('entregado', PedidoHistorial::where('pedido_id', $servida->id)->latest('id')->first()->estado_anterior);
        $this->assertSame('anulada', $cuenta->fresh()->estado);
        $this->assertSame([], GestionarCuentas::mesasInconsistentes($this->restaurante->id));
    }

    public function test_the_waiter_voids_only_an_own_account_without_live_orders(): void
    {
        $conPedidos = $this->cuenta([0]);
        $this->ronda($conPedidos);
        $soloCanceladas = $this->cuenta([1]);
        app(GestionarPedidoSalon::class)->cancelarRonda($this->cajero, $soloCanceladas, $this->ronda($soloCanceladas), 'en_preparacion', 'Error');
        $vacia = $this->cuenta([2]);

        $this->actingAs($this->mesero);
        $this->patchJson(route('panel.mesas.cuentas.anular', $conPedidos), ['version' => 1, 'motivo' => 'Se fueron'])->assertForbidden();
        $this->patchJson(route('panel.mesas.cuentas.anular', $soloCanceladas), ['version' => 1, 'motivo' => 'Se fueron'])->assertOk();
        $this->patchJson(route('panel.mesas.cuentas.anular', $vacia), ['version' => 1, 'motivo' => 'Se fueron'])->assertOk();

        $this->assertSame('abierta', $conPedidos->fresh()->estado);
    }

    public function test_the_service_rechecks_the_waiter_rule_with_the_restaurant_locked(): void
    {
        $cuenta = $this->cuenta();
        $this->ronda($cuenta);

        $this->expectException(AuthorizationException::class);
        app(GestionarCuentas::class)->anular($this->mesero, $cuenta, 1, 'Intento directo');
    }

    /* ---------------------------------------------------------------------- */
    /* Fusionar */
    /* ---------------------------------------------------------------------- */

    public function test_merging_moves_the_rounds_to_the_end_of_the_first_account(): void
    {
        $principal = $this->cuenta([0]);
        $otra = $this->cuenta([1]);
        $p1 = $this->ronda($principal);
        $p2 = $this->ronda($principal);
        $o1 = $this->ronda($otra, 1, 0);
        $o2 = $this->ronda($otra, 0, 1);
        $o3 = $this->ronda($otra, 1, 1);

        app(GestionarCuentas::class)->fusionar($this->cajero, $principal, 1, $otra, 1);

        $this->assertSame([1, 2], [$p1->fresh()->ronda, $p2->fresh()->ronda], 'Las rondas de la primera no cambian.');
        $this->assertSame([3, 4, 5], [$o1->fresh()->ronda, $o2->fresh()->ronda, $o3->fresh()->ronda]);
        $this->assertSame([$principal->id], Pedido::pluck('cuenta_id')->unique()->values()->all());
        $this->assertSame('Pasó a la cuenta #1 como ronda 4. Era la ronda 2 de la cuenta #2.', PedidoHistorial::where('pedido_id', $o2->id)->latest('id')->first()->nota);
        $this->assertSame(8100 * 2 + 3650 + 800 + 4450, $this->total($principal));
        $this->assertSame(0, $this->total($otra));

        // La siguiente ronda sigue la numeración.
        $this->assertSame(6, $this->ronda($principal->fresh())->ronda);
    }

    public function test_merging_into_a_bill_request_reopens_it(): void
    {
        $principal = $this->cuenta([0]);
        $otra = $this->cuenta([1]);
        $this->ronda($principal);
        $this->ronda($otra);
        app(GestionarPedidoSalon::class)->pedirPrecuenta($this->mesero, $principal, 1);

        app(GestionarCuentas::class)->fusionar($this->cajero, $principal, 2, $otra, 1);

        $this->assertSame('abierta', $principal->fresh()->estado);
        $this->assertNotNull(Alerta::sole()->resuelta_at);
    }

    /* ---------------------------------------------------------------------- */
    /* Ventas de hoy */
    /* ---------------------------------------------------------------------- */

    public function test_a_salon_round_counts_as_a_sale_when_its_account_closes_today_not_when_served(): void
    {
        $cerrada = $this->cuenta([0]);
        $servida = $this->listo($this->ronda($cerrada, 2, 1));
        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.pedidos.servir', [$cerrada, $servida]))->assertOk();

        $abierta = $this->cuenta([1]);
        $soloServida = $this->listo($this->ronda($abierta, 1, 0));
        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.pedidos.servir', [$abierta, $soloServida]))->assertOk();

        $anulada = $this->cuenta([2]);
        $this->ronda($anulada, 3, 0);
        app(GestionarCuentas::class)->anular($this->cajero, $anulada, 1, 'Se fueron');

        Pedido::factory()->create(['restaurante_id' => $this->restaurante->id, 'numero' => 900, 'estado' => 'entregado', 'entregado_at' => now(), 'total' => 20]);

        // Antes de cerrar: solo el recojo entregado. Ni lo servido ni lo de la cuenta anulada.
        $this->assertSame(['20.00', 'De 1 entrega'], $this->tarjetaVentas());

        app(GestionarCuentas::class)->cerrarExterno($this->cajero, $cerrada, 1, $this->total($cerrada));

        $this->assertSame(['101.00', 'De 1 entrega y 1 cuenta de salón'], $this->tarjetaVentas());
    }

    public function test_an_account_closed_yesterday_does_not_count_today(): void
    {
        $cuenta = $this->cuenta();
        $this->ronda($cuenta);
        app(GestionarCuentas::class)->cerrarExterno($this->cajero, $cuenta, 1, $this->total($cuenta), true);

        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00', 'America/Lima'));

        $this->assertNotSame('81.00', $this->tarjetaVentas()[0] ?? null);
    }

    /**
     * Cifra y leyenda de la tarjeta «Ventas de hoy» del inicio (sin el resto de la página).
     *
     * @return array{0: string, 1: string}|array{}
     */
    private function tarjetaVentas(): array
    {
        $html = $this->actingAs($this->cajero->fresh())->get(route('panel.inicio'))->assertOk()->getContent();

        if (! preg_match('/<[^>]*data-tarjeta="ventas-hoy".*?<\/(?:a|div|section)>\s*<\/(?:a|div|section)>/s', $html, $tarjeta)) {
            return [];
        }

        $texto = trim(preg_replace('/\s+/u', ' ', strip_tags($tarjeta[0])));
        preg_match('/(\d+\.\d{2})/', $texto, $cifra);
        preg_match('/(De .*|Aún sin entregas hoy)$/u', $texto, $leyenda);

        return [$cifra[1] ?? '', trim($leyenda[1] ?? '')];
    }

    /* ---------------------------------------------------------------------- */
    /* Plano y detalle */
    /* ---------------------------------------------------------------------- */

    public function test_the_map_shows_the_amount_and_the_ready_mark(): void
    {
        $cuenta = $this->cuenta([0, 1]);
        $this->listo($this->ronda($cuenta, 2, 1));
        $this->ronda($cuenta, 1, 0);

        $mesa = $this->actingAs($this->mesero)->getJson(route('panel.mesas.plano'))->assertOk()->json('mesas.'.$this->mesas[0]->id);

        $this->assertStringContainsString("S/\u{00A0}117.50", $mesa);
        $this->assertStringContainsString('Listo para servir', $mesa);

        $this->actingAs($this->mesero)->get(route('panel.mesas.index'))->assertSee('Listo para servir')->assertSee('117.50');
    }

    public function test_the_account_detail_shows_rounds_lines_and_the_server_total(): void
    {
        $cuenta = $this->cuenta();
        $this->listo($this->ronda($cuenta, 2, 1));
        $this->ronda($cuenta, 1, 0);
        app(GestionarPedidoSalon::class)->anularLinea($this->cajero, $cuenta, PedidoItem::where('nombre', 'Chicha morada')->sole(), 'No había');

        $this->actingAs($this->mesero)->get(route('panel.mesas.cuentas.show', $cuenta), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSeeInOrder(['Ronda 1', 'Listo para servir', '2', 'Lomo saltado', 'Chicha morada', 'Anulada: No había', 'Ronda 2', 'En preparación'])
            ->assertSee('data-cuenta-total="10950"', false)
            ->assertSee('Agregar más')
            ->assertSee('Servido')
            ->assertSee(route('panel.mesas.cuentas.pedido.create', $cuenta), false)
            ->assertDontSee('Anular esta línea')
            ->assertDontSee('Cancelar la ronda entera');

        $this->actingAs($this->cajero)->get(route('panel.mesas.cuentas.show', $cuenta), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertSee('Anular esta línea')->assertSee('Cancelar la ronda entera');
    }

    /**
     * El detalle se abre muchas veces por servicio: sus consultas no pueden crecer con las rondas.
     */
    public function test_the_account_detail_runs_a_bounded_number_of_queries(): void
    {
        // La base ya tiene de todo un poco (una línea anulada, otra cuenta abierta): así se mide que
        // las consultas no crecen con la cantidad, no si una relación vacía se salta su consulta.
        $cuenta = $this->cuenta([0]);
        $this->ronda($cuenta);
        $this->cuenta([3]);
        app(GestionarPedidoSalon::class)->anularLinea($this->admin, $cuenta, PedidoItem::first(), 'Prueba');
        $pocas = $this->consultas(fn () => $this->actingAs($this->cajero->fresh())->get(route('panel.mesas.cuentas.show', $cuenta), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk());

        foreach (range(1, 12) as $n) {
            $this->ronda($cuenta, 2, 2);
        }

        $this->cuenta([4]);
        $this->cuenta([5]);
        PedidoItem::query()->whereNull('anulado_at')->limit(5)->get()->each(fn (PedidoItem $item) => app(GestionarPedidoSalon::class)->anularLinea($this->admin, $cuenta, $item, 'Prueba'));

        $muchas = $this->consultas(fn () => $this->actingAs($this->cajero->fresh())->get(route('panel.mesas.cuentas.show', $cuenta), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('Ronda 13'));

        $this->assertSame($pocas, $muchas, "El detalle hizo {$pocas} consultas con 1 ronda y {$muchas} con 13.");
    }

    public function test_the_map_still_runs_a_fixed_number_of_queries_with_rounds(): void
    {
        $this->ronda($this->cuenta([0]));
        $pocas = $this->consultas(fn () => $this->actingAs($this->mesero->fresh())->getJson(route('panel.mesas.plano'))->assertOk());

        foreach (range(1, 6) as $i) {
            $cuenta = $this->cuenta([$i]);

            foreach (range(1, 4) as $n) {
                $this->ronda($cuenta);
            }
        }

        $muchas = $this->consultas(fn () => $this->actingAs($this->mesero->fresh())->getJson(route('panel.mesas.plano'))->assertOk());

        $this->assertSame($pocas, $muchas, "El plano hizo {$pocas} consultas con 1 cuenta y {$muchas} con 7 y 25 rondas.");
    }

    private function consultas(\Closure $peticion): int
    {
        $total = 0;
        DB::listen(function () use (&$total): void {
            $total++;
        });

        $peticion();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $total;
    }

    /* ---------------------------------------------------------------------- */
    /* Local fuera de operación */
    /* ---------------------------------------------------------------------- */

    public function test_out_of_operation_serving_closing_and_voiding_still_work(): void
    {
        $cuenta = $this->cuenta([0]);
        $pedido = $this->listo($this->ronda($cuenta));
        $otra = $this->cuenta([1]);
        $this->ronda($otra);
        $item = PedidoItem::where('pedido_id', $pedido->id)->first();
        Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'vencida', 'vence_el' => '2026-10-01']);

        $this->actingAs($this->cajero->fresh());
        $this->patchJson(route('panel.mesas.cuentas.lineas.anular', [$cuenta, $item->id]), ['motivo' => 'No'])->assertForbidden();
        $this->patchJson(route('panel.mesas.cuentas.pedidos.cancelar', [$cuenta, $pedido]), ['desde' => 'listo', 'motivo' => 'No va'])->assertForbidden();
        $this->patchJson(route('panel.mesas.cuentas.precuenta', $cuenta), ['version' => 1])->assertForbidden();

        $this->actingAs($this->mesero->fresh())->patchJson(route('panel.mesas.cuentas.pedidos.servir', [$cuenta, $pedido]))->assertOk();
        $this->actingAs($this->cajero->fresh())->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => $this->total($cuenta)])->assertOk();
        $this->patchJson(route('panel.mesas.cuentas.anular', $otra), ['version' => 1, 'motivo' => 'Local fuera de operación'])->assertOk();

        $this->assertSame(['cerrada', 'anulada'], [$cuenta->fresh()->estado, $otra->fresh()->estado]);
        $this->assertNull($item->fresh()->anulado_at);
    }

    public function test_rounds_never_leak_to_another_restaurant(): void
    {
        $cuenta = $this->cuenta();
        $pedido = $this->listo($this->ronda($cuenta));
        $ajeno = User::factory()->admin(Restaurante::factory()->conModulos('mesas')->create())->create();

        $this->actingAs($ajeno);
        $this->patchJson(route('panel.mesas.cuentas.pedidos.servir', [$cuenta, $pedido]))->assertNotFound();
        $this->patchJson(route('panel.mesas.cuentas.precuenta', $cuenta), ['version' => 1])->assertNotFound();
        $this->get(route('panel.mesas.cuentas.precuenta.imprimir', $cuenta))->assertNotFound();
        $this->patchJson(route('panel.mesas.cuentas.lineas.anular', [$cuenta, PedidoItem::first()->id]), ['motivo' => 'Ajeno'])->assertNotFound();
        $this->get(route('panel.pedidos.show', $pedido))->assertNotFound();

        $this->assertSame('listo', $pedido->fresh()->estado);
    }

    public function test_unserved_exception_carries_the_rounds(): void
    {
        $error = new RondasSinServir([['id' => 1, 'numero' => 3, 'ronda' => 1, 'estado' => 'listo']]);

        $this->assertSame(409, $error->getStatusCode());
        $this->assertSame(1, $error->rondas[0]['ronda']);
    }
}
