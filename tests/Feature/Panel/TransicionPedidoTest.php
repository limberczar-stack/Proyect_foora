<?php

namespace Tests\Feature\Panel;

use App\Models\Alerta;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\PedidoHistorial;
use App\Models\Restaurante;
use App\Models\User;
use App\Services\GestionarPedido;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransicionPedidoTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    private MetodoPago $yape;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-07 15:00', 'America/Lima'));

        $this->restaurante = Restaurante::factory()->create();
        $this->admin = User::factory()->admin($this->restaurante)->create(['name' => 'Rosa Quispe']);
        $this->yape = MetodoPago::create(['codigo' => 'yape', 'nombre' => 'Yape', 'requiere_validacion' => true]);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function pedido(array $atributos = []): Pedido
    {
        return Pedido::factory()->create(['restaurante_id' => $this->restaurante->id, ...$atributos]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function cambiar(Pedido $pedido, string $destino, ?string $desde = null, array $extra = []): TestResponse
    {
        return $this->patchJson(route('panel.pedidos.estado', $pedido), [
            'desde' => $desde ?? $pedido->estado,
            'destino' => $destino,
            ...$extra,
        ]);
    }

    private function alerta(Pedido $pedido, string $tipo): Alerta
    {
        return Alerta::create([
            'restaurante_id' => $pedido->restaurante_id,
            'tipo' => $tipo,
            'prioridad' => $tipo === 'pago_pendiente' ? 'urgente' : 'media',
            'titulo' => 'Alerta',
            'referencia_tipo' => 'pedido',
            'referencia_id' => $pedido->id,
        ]);
    }

    public function test_accepting_sets_the_date_history_with_user_and_resolves_the_new_order_alert(): void
    {
        $pedido = $this->pedido();
        $nuevo = $this->alerta($pedido, 'pedido_nuevo');
        $pago = $this->alerta($pedido, 'pago_pendiente');

        $this->actingAs($this->admin);
        $this->cambiar($pedido, 'en_preparacion')->assertOk()->assertJsonPath('estado', 'en_preparacion');

        $pedido->refresh();
        $this->assertSame('en_preparacion', $pedido->estado);
        $this->assertTrue($pedido->aceptado_at->equalTo(now()));

        $registro = PedidoHistorial::where('pedido_id', $pedido->id)->sole();
        $this->assertSame('nuevo', $registro->estado_anterior);
        $this->assertSame('en_preparacion', $registro->estado_nuevo);
        $this->assertSame($this->admin->id, $registro->user_id);

        $this->assertNotNull($nuevo->fresh()->resuelta_at);
        $this->assertNull($pago->fresh()->resuelta_at);
    }

    public function test_full_delivery_flow_sets_each_date(): void
    {
        $pedido = $this->pedido(['tipo' => 'delivery', 'direccion_entrega' => 'Jr. Lima 1']);
        $this->actingAs($this->admin);

        $this->cambiar($pedido, 'en_preparacion')->assertOk();
        $this->cambiar($pedido, 'listo', 'en_preparacion')->assertOk();
        $this->cambiar($pedido, 'en_camino', 'listo', ['minutos' => 25])->assertOk();
        $this->cambiar($pedido, 'entregado', 'en_camino', ['cobrado' => true])->assertOk();

        $pedido->refresh();
        $this->assertSame('entregado', $pedido->estado);
        $this->assertNotNull($pedido->listo_at);
        $this->assertNull($pedido->recoger_antes_de);
        $this->assertTrue($pedido->eta_at->equalTo(now()->addMinutes(25)));
        $this->assertNotNull($pedido->salida_at);
        $this->assertNotNull($pedido->entregado_at);
        $this->assertSame('pagado', $pedido->estado_pago);
        $this->assertSame($this->admin->id, $pedido->pago_validado_por);
        $this->assertSame(['en_preparacion', 'listo', 'en_camino', 'entregado'], PedidoHistorial::where('pedido_id', $pedido->id)->orderBy('id')->pluck('estado_nuevo')->all());
    }

    public function test_pickup_ready_sets_pickup_deadline_and_is_collected_directly(): void
    {
        $pedido = $this->pedido(['estado' => 'en_preparacion']);
        $this->actingAs($this->admin);

        $this->cambiar($pedido, 'listo')->assertOk();
        $this->assertTrue($pedido->fresh()->recoger_antes_de->equalTo(now()->addMinutes(Pedido::MINUTOS_PARA_RECOGER)));

        $this->cambiar($pedido, 'entregado', 'listo', ['cobrado' => true])
            ->assertOk()
            ->assertJsonPath('mensaje', "Pedido #{$pedido->numero}: recogido.");
    }

    public function test_on_the_way_uses_twenty_minutes_by_form_default_and_validates_range(): void
    {
        $pedido = $this->pedido(['tipo' => 'delivery', 'estado' => 'listo']);
        $this->actingAs($this->admin);

        $this->cambiar($pedido, 'en_camino')->assertUnprocessable()->assertJsonValidationErrors(['minutos' => 'Indica en cuántos minutos llega.']);
        $this->cambiar($pedido, 'en_camino', null, ['minutos' => 0])->assertJsonValidationErrors('minutos');
        $this->cambiar($pedido, 'en_camino', null, ['minutos' => 181])->assertJsonValidationErrors('minutos');
        $this->cambiar($pedido, 'en_camino', null, ['minutos' => Pedido::MINUTOS_ETA_POR_DEFECTO])->assertOk();

        $this->assertSame(20, (int) $pedido->fresh()->salida_at->diffInMinutes($pedido->fresh()->eta_at));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function transicionesInvalidas(): array
    {
        return [
            'nuevo a listo' => ['nuevo', 'recojo', 'listo'],
            'nuevo a entregado' => ['nuevo', 'recojo', 'entregado'],
            'en preparación a entregado' => ['en_preparacion', 'recojo', 'entregado'],
            'en preparación a en camino' => ['en_preparacion', 'delivery', 'en_camino'],
            'recojo listo a en camino' => ['listo', 'recojo', 'en_camino'],
            'delivery listo a entregado' => ['listo', 'delivery', 'entregado'],
            'entregado a cancelado' => ['entregado', 'recojo', 'cancelado'],
            'cancelado a en preparación' => ['cancelado', 'recojo', 'en_preparacion'],
            'en camino a listo' => ['en_camino', 'delivery', 'listo'],
            'listo a nuevo' => ['listo', 'recojo', 'nuevo'],
        ];
    }

    #[DataProvider('transicionesInvalidas')]
    public function test_invalid_transitions_are_rejected_with_a_clear_message(string $estado, string $tipo, string $destino): void
    {
        $pedido = $this->pedido(['estado' => $estado, 'tipo' => $tipo]);

        $this->actingAs($this->admin);
        $this->cambiar($pedido, $destino, null, ['minutos' => 20, 'motivo' => 'otro'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destino');

        $this->assertSame($estado, $pedido->fresh()->estado);
        $this->assertDatabaseCount('pedido_historial', 0);
    }

    public function test_messages_explain_pickup_and_delivery_rules(): void
    {
        $this->actingAs($this->admin);

        $this->cambiar($this->pedido(['estado' => 'listo', 'tipo' => 'recojo']), 'en_camino', null, ['minutos' => 20])
            ->assertJsonValidationErrors(['destino' => 'Un pedido de recojo no sale en camino']);
        $this->cambiar($this->pedido(['estado' => 'listo', 'tipo' => 'delivery', 'numero' => 99]), 'entregado')
            ->assertJsonValidationErrors(['destino' => 'debe salir en camino']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cancelables(): array
    {
        return [
            'nuevo' => ['nuevo', 'recojo'],
            'en preparación' => ['en_preparacion', 'recojo'],
            'listo (no recogió)' => ['listo', 'recojo'],
            'en camino' => ['en_camino', 'delivery'],
        ];
    }

    #[DataProvider('cancelables')]
    public function test_cancelling_requires_a_reason_and_resolves_every_alert(string $estado, string $tipo): void
    {
        $pedido = $this->pedido(['estado' => $estado, 'tipo' => $tipo]);
        $alertas = [$this->alerta($pedido, 'pedido_nuevo'), $this->alerta($pedido, 'pago_pendiente')];

        $this->actingAs($this->admin);
        $this->cambiar($pedido, 'cancelado')->assertUnprocessable()->assertJsonValidationErrors(['motivo' => 'Elige el motivo de la cancelación.']);
        $this->cambiar($pedido, 'cancelado', null, ['motivo' => 'inventado'])->assertJsonValidationErrors('motivo');

        $this->cambiar($pedido, 'cancelado', null, ['motivo' => 'cliente_no_llego', 'detalle' => 'Esperamos 40 min'])->assertOk();

        $pedido->refresh();
        $this->assertSame('cancelado', $pedido->estado);
        $this->assertSame('cliente_no_llego', $pedido->cancelacion_motivo);
        $this->assertSame('Esperamos 40 min', $pedido->cancelacion_detalle);
        $this->assertNotNull($pedido->cancelado_at);
        $this->assertSame('Motivo: El cliente no recogió.', PedidoHistorial::where('pedido_id', $pedido->id)->sole()->nota);

        foreach ($alertas as $alerta) {
            $this->assertNotNull($alerta->fresh()->resuelta_at);
        }
    }

    public function test_stale_state_from_a_second_user_fails_with_conflict_and_changes_nothing(): void
    {
        $pedido = $this->pedido();
        $cajero = User::factory()->cajero($this->restaurante)->create();

        $this->actingAs($this->admin);
        $this->cambiar($pedido, 'en_preparacion', 'nuevo')->assertOk();

        $this->actingAs($cajero);
        $this->cambiar($pedido, 'cancelado', 'nuevo', ['motivo' => 'otro'])
            ->assertConflict()
            ->assertJsonPath('message', GestionarPedido::MENSAJE_DESACTUALIZADO);

        $this->assertSame('en_preparacion', $pedido->fresh()->estado);
        $this->assertDatabaseCount('pedido_historial', 1);
    }

    public function test_the_same_action_twice_succeeds_only_once(): void
    {
        $pedido = $this->pedido();
        $this->actingAs($this->admin);

        $this->cambiar($pedido, 'en_preparacion', 'nuevo')->assertOk();
        $this->cambiar($pedido, 'en_preparacion', 'nuevo')->assertConflict();

        $this->assertDatabaseCount('pedido_historial', 1);
    }

    public function test_yape_payment_can_be_validated_and_resolves_its_alert(): void
    {
        $pedido = $this->pedido(['metodo_pago_id' => $this->yape->id, 'estado_pago' => 'por_verificar']);
        $alerta = $this->alerta($pedido, 'pago_pendiente');
        $nuevo = $this->alerta($pedido, 'pedido_nuevo');

        $this->actingAs($this->admin)
            ->patchJson(route('panel.pedidos.pago', $pedido), ['accion' => 'validar'])
            ->assertOk()
            ->assertJsonPath('estado_pago', 'pagado');

        $pedido->refresh();
        $this->assertSame('pagado', $pedido->estado_pago);
        $this->assertSame($this->admin->id, $pedido->pago_validado_por);
        $this->assertNotNull($pedido->pago_validado_at);
        $this->assertSame('nuevo', $pedido->estado);
        $this->assertNotNull($alerta->fresh()->resuelta_at);
        $this->assertNull($nuevo->fresh()->resuelta_at);
        $this->assertSame('Pago validado.', PedidoHistorial::where('pedido_id', $pedido->id)->sole()->nota);
    }

    public function test_rejecting_a_payment_does_not_cancel_the_order(): void
    {
        $pedido = $this->pedido(['metodo_pago_id' => $this->yape->id, 'estado_pago' => 'por_verificar', 'estado' => 'en_preparacion']);
        $alerta = $this->alerta($pedido, 'pago_pendiente');

        $this->actingAs($this->admin)
            ->patchJson(route('panel.pedidos.pago', $pedido), ['accion' => 'rechazar'])
            ->assertOk()
            ->assertJsonPath('mensaje', fn (string $mensaje) => str_contains($mensaje, 'cancela el pedido'));

        $pedido->refresh();
        $this->assertSame('rechazado', $pedido->estado_pago);
        $this->assertSame('en_preparacion', $pedido->estado);
        $this->assertNotNull($alerta->fresh()->resuelta_at);

        $this->get(route('panel.pedidos.show', $pedido), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertSee('Cancelar por pago no confirmado');
    }

    public function test_payment_already_reviewed_or_not_to_verify_conflicts(): void
    {
        $this->actingAs($this->admin);

        $pagado = $this->pedido(['metodo_pago_id' => $this->yape->id, 'estado_pago' => 'pagado']);
        $this->patchJson(route('panel.pedidos.pago', $pagado), ['accion' => 'rechazar'])->assertConflict();
        $this->assertSame('pagado', $pagado->fresh()->estado_pago);

        $efectivo = $this->pedido(['numero' => 50]);
        $this->patchJson(route('panel.pedidos.pago', $efectivo), ['accion' => 'validar'])->assertConflict();

        $this->patchJson(route('panel.pedidos.pago', $efectivo), ['accion' => 'otra'])->assertUnprocessable();
    }

    public function test_payment_to_verify_blocks_delivery_and_a_rejected_one_needs_confirmation(): void
    {
        $pedido = $this->pedido(['estado' => 'listo', 'metodo_pago_id' => $this->yape->id, 'estado_pago' => 'por_verificar']);
        $this->actingAs($this->admin);

        $this->cambiar($pedido, 'entregado', null, ['cobrado' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cobrado' => 'Valida o rechaza el pago']);

        $pedido->forceFill(['estado_pago' => 'rechazado'])->save();

        $this->cambiar($pedido, 'entregado')->assertJsonValidationErrors(['cobrado' => 'Cobrado']);
        $this->cambiar($pedido, 'entregado', null, ['cobrado' => true])->assertOk();

        $this->assertSame('pagado', $pedido->fresh()->estado_pago);
    }

    public function test_cash_left_unpaid_on_delivery_stays_pending(): void
    {
        $pedido = $this->pedido(['estado' => 'listo']);

        $this->actingAs($this->admin);
        $this->cambiar($pedido, 'entregado', null, ['cobrado' => false])->assertOk();

        $this->assertSame('pendiente', $pedido->fresh()->estado_pago);
        $this->assertNull($pedido->fresh()->pago_validado_por);
    }

    public function test_actions_require_csrf_outside_tests_and_only_patch(): void
    {
        $pedido = $this->pedido();
        $this->actingAs($this->admin);

        $this->get(route('panel.pedidos.index').'/'.$pedido->id.'/estado')->assertMethodNotAllowed();
        $this->postJson(route('panel.pedidos.estado', $pedido), ['desde' => 'nuevo', 'destino' => 'en_preparacion'])->assertMethodNotAllowed();
    }

    public function test_form_submission_without_javascript_redirects_back_with_status(): void
    {
        $pedido = $this->pedido();

        $this->actingAs($this->admin)
            ->patch(route('panel.pedidos.estado', $pedido), ['desde' => 'nuevo', 'destino' => 'en_preparacion'])
            ->assertRedirect(route('panel.pedidos.index', ['estado' => 'en_preparacion', 'pedido' => $pedido->id]))
            ->assertSessionHas('status', "Pedido #{$pedido->numero}: aceptado.");
    }
}
