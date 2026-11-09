<?php

namespace Tests\Feature\Superadmin;

use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\SuscripcionPago;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PagosSuscripcionTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private Restaurante $restaurante;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'America/Lima'));

        $this->superadmin = User::factory()->superadmin()->create();
        $this->plan = Plan::create(['nombre' => 'Pro', 'precio_mensual' => 79]);
        $this->restaurante = Restaurante::factory()->create(['plan_id' => $this->plan->id]);
        $this->actingAs($this->superadmin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function suscripcion(array $atributos = []): Suscripcion
    {
        return Suscripcion::factory()->create([
            'restaurante_id' => $this->restaurante->id,
            'plan_id' => $this->plan->id,
            'estado' => 'activa',
            'inicia_el' => '2026-10-01',
            'vence_el' => '2026-11-20',
            'precio_pactado' => 79,
            ...$atributos,
        ]);
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    private function pagar(array $cambios = []): TestResponse
    {
        return $this->postJson(route('panel.superadmin.restaurantes.pagos.store', $this->restaurante), [
            'monto' => '79.00',
            'metodo' => 'Yape',
            'referencia' => 'OP-1001',
            'periodo_desde' => '2026-11-21',
            'periodo_hasta' => '2026-12-20',
            ...$cambios,
        ]);
    }

    private function anular(SuscripcionPago $pago, string $motivo = 'Se registró por error'): TestResponse
    {
        return $this->patchJson(route('panel.superadmin.restaurantes.pagos.anular', [$this->restaurante, $pago->id]), ['motivo' => $motivo]);
    }

    public function test_a_payment_extends_the_due_date_and_activates(): void
    {
        $suscripcion = $this->suscripcion(['estado' => 'vencida', 'vence_el' => '2026-11-05']);

        $this->pagar(['periodo_desde' => '2026-11-10', 'periodo_hasta' => '2026-12-09'])->assertOk();

        $suscripcion->refresh();
        $this->assertSame('activa', $suscripcion->estado);
        $this->assertSame('2026-12-09', $suscripcion->vence_el->toDateString());

        $pago = SuscripcionPago::sole();
        $this->assertSame('79.00', $pago->monto);
        $this->assertSame('OP-1001', $pago->referencia);
        $this->assertSame($this->superadmin->id, $pago->registrado_por);
        $this->assertSame('2026-11-05', $pago->vence_antes->toDateString());
        $this->assertSame('vencida', $pago->estado_antes);
    }

    public function test_a_payment_never_moves_the_due_date_backwards(): void
    {
        $suscripcion = $this->suscripcion(['vence_el' => '2027-01-31']);

        $this->pagar(['periodo_desde' => '2026-11-01', 'periodo_hasta' => '2026-11-30'])->assertOk();

        $this->assertSame('2027-01-31', $suscripcion->fresh()->vence_el->toDateString());
    }

    public function test_a_trial_gets_its_plan_price_when_it_starts_paying(): void
    {
        $suscripcion = $this->suscripcion(['estado' => 'prueba', 'precio_pactado' => 0]);

        $this->pagar()->assertOk();

        $suscripcion->refresh();
        $this->assertSame('activa', $suscripcion->estado);
        $this->assertSame('79.00', $suscripcion->precio_pactado);
    }

    public function test_a_payment_for_a_period_already_past_does_not_fake_an_active_state(): void
    {
        $suscripcion = $this->suscripcion(['estado' => 'vencida', 'vence_el' => '2026-10-01']);

        $this->pagar(['periodo_desde' => '2026-10-01', 'periodo_hasta' => '2026-10-31'])
            ->assertOk()
            ->assertJsonPath('mensaje', 'Pago registrado, pero su periodo ya pasó: la suscripción sigue vencida desde el 31/10/2026.');

        $suscripcion->refresh();
        $this->assertSame('vencida', $suscripcion->estado);
        $this->assertSame('2026-10-31', $suscripcion->vence_el->toDateString());
    }

    public function test_paying_after_midnight_in_lima_uses_the_lima_date(): void
    {
        // 23:30 del 10 en Lima ya es el 11 en UTC: un periodo que termina el 10 sigue vigente.
        Carbon::setTestNow(Carbon::parse('2026-11-10 23:30', 'America/Lima'));
        $suscripcion = $this->suscripcion(['estado' => 'vencida', 'vence_el' => '2026-11-01']);

        $this->pagar(['periodo_desde' => '2026-11-02', 'periodo_hasta' => '2026-11-10'])->assertOk();

        $this->assertSame('activa', $suscripcion->fresh()->estado);
    }

    public function test_double_submit_with_the_same_reference_is_rejected(): void
    {
        $suscripcion = $this->suscripcion();

        $this->pagar()->assertOk();
        $vence = $suscripcion->fresh()->vence_el->toDateString();

        $this->pagar(['periodo_desde' => '2026-12-21', 'periodo_hasta' => '2027-01-20'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['referencia' => 'Ya hay un pago registrado con esta referencia.']);

        // Sin importar mayúsculas ni espacios.
        $this->pagar(['referencia' => '  op-1001 '])->assertUnprocessable()->assertJsonValidationErrors('referencia');

        $this->assertSame(1, SuscripcionPago::count());
        $this->assertSame($vence, $suscripcion->fresh()->vence_el->toDateString());
    }

    public function test_double_submit_without_reference_is_rejected_too(): void
    {
        $this->suscripcion();

        $this->pagar(['referencia' => ''])->assertOk();
        $this->pagar(['referencia' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pago');

        $this->assertSame(1, SuscripcionPago::count());

        // Otro periodo sin referencia sí es otro pago.
        $this->pagar(['referencia' => '', 'periodo_desde' => '2026-12-21', 'periodo_hasta' => '2027-01-20'])->assertOk();
        $this->assertSame(2, SuscripcionPago::count());
    }

    public function test_the_same_reference_in_another_local_is_allowed(): void
    {
        $this->suscripcion();
        $otro = Restaurante::factory()->create();
        Suscripcion::factory()->create(['restaurante_id' => $otro->id, 'plan_id' => $this->plan->id]);

        $this->pagar()->assertOk();

        $this->postJson(route('panel.superadmin.restaurantes.pagos.store', $otro), [
            'monto' => '79.00', 'metodo' => 'Yape', 'referencia' => 'OP-1001', 'periodo_desde' => '2026-11-21', 'periodo_hasta' => '2026-12-20',
        ])->assertOk();
    }

    public function test_voiding_restores_the_due_date_and_recalculates_the_state(): void
    {
        $suscripcion = $this->suscripcion(['estado' => 'prueba', 'precio_pactado' => 0]);

        $this->pagar()->assertOk();
        $pago = SuscripcionPago::sole();
        $this->assertSame('activa', $suscripcion->fresh()->estado);

        $this->anular($pago)->assertOk();

        $suscripcion->refresh();
        $this->assertSame('prueba', $suscripcion->estado);
        $this->assertSame('2026-11-20', $suscripcion->vence_el->toDateString());
        $this->assertSame('0.00', $suscripcion->precio_pactado);

        // No se borró: queda marcado y con su motivo.
        $pago->refresh();
        $this->assertNotNull($pago->anulado_at);
        $this->assertSame($this->superadmin->id, $pago->anulado_por);
        $this->assertSame('Se registró por error', $pago->motivo_anulacion);
        $this->assertSame(1, SuscripcionPago::count());
    }

    public function test_voiding_when_the_old_date_already_passed_leaves_it_expired(): void
    {
        $suscripcion = $this->suscripcion(['estado' => 'vencida', 'vence_el' => '2026-11-05']);

        $this->pagar(['periodo_desde' => '2026-11-06', 'periodo_hasta' => '2026-12-05'])->assertOk();
        $this->anular(SuscripcionPago::sole())->assertOk();

        $suscripcion->refresh();
        $this->assertSame('vencida', $suscripcion->estado);
        $this->assertSame('2026-11-05', $suscripcion->vence_el->toDateString());
    }

    public function test_voiding_an_active_one_whose_date_passed_meanwhile_marks_it_expired(): void
    {
        $suscripcion = $this->suscripcion(['vence_el' => '2026-11-12']);

        $this->pagar(['periodo_desde' => '2026-11-13', 'periodo_hasta' => '2026-12-12'])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00', 'America/Lima'));

        $this->anular(SuscripcionPago::sole())->assertOk();

        $this->assertSame('vencida', $suscripcion->fresh()->estado);
    }

    public function test_voiding_a_payment_that_reopened_a_cancelled_one_cancels_it_again(): void
    {
        $suscripcion = $this->suscripcion(['estado' => 'cancelada']);

        $this->pagar()->assertOk();
        $this->assertSame('activa', $suscripcion->fresh()->estado);

        $this->anular(SuscripcionPago::sole())->assertOk();

        $this->assertSame('cancelada', $suscripcion->fresh()->estado);
    }

    public function test_only_the_last_valid_payment_can_be_voided_and_only_once(): void
    {
        $suscripcion = $this->suscripcion();

        $this->pagar()->assertOk();
        $this->pagar(['referencia' => 'OP-1002', 'periodo_desde' => '2026-12-21', 'periodo_hasta' => '2027-01-20'])->assertOk();
        [$primero, $segundo] = SuscripcionPago::orderBy('id')->get()->all();

        $this->anular($primero)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['motivo' => 'Solo se puede anular el último pago vigente. Anula primero los posteriores.']);

        $this->anular($segundo)->assertOk();
        $this->assertSame('2026-12-20', $suscripcion->fresh()->vence_el->toDateString());
        $this->assertSame('activa', $suscripcion->fresh()->estado);

        $this->anular($segundo)->assertUnprocessable()->assertJsonValidationErrors(['motivo' => 'Este pago ya estaba anulado.']);

        $this->anular($primero)->assertOk();
        $this->assertSame('2026-11-20', $suscripcion->fresh()->vence_el->toDateString());
    }

    public function test_a_voided_reference_can_be_registered_again(): void
    {
        $this->suscripcion();

        $this->pagar()->assertOk();
        $this->anular(SuscripcionPago::sole())->assertOk();
        $this->pagar()->assertOk();

        $this->assertSame(2, SuscripcionPago::count());
    }

    public function test_a_payment_of_another_local_cannot_be_voided_from_this_one(): void
    {
        $this->suscripcion();
        $otro = Restaurante::factory()->create();
        $ajena = Suscripcion::factory()->create(['restaurante_id' => $otro->id, 'plan_id' => $this->plan->id]);
        $pago = SuscripcionPago::create([
            'suscripcion_id' => $ajena->id, 'monto' => 10, 'metodo' => 'Yape', 'periodo_desde' => '2026-11-01', 'periodo_hasta' => '2026-11-30',
        ]);

        $this->anular($pago)->assertNotFound();
        $this->assertNull($pago->fresh()->anulado_at);
    }

    public function test_there_is_no_route_that_deletes_payments(): void
    {
        $this->suscripcion();
        $this->pagar()->assertOk();

        $this->deleteJson('/panel/superadmin/restaurantes/'.$this->restaurante->id.'/pagos/'.SuscripcionPago::sole()->id)->assertNotFound();
        $this->assertSame(1, SuscripcionPago::count());
    }

    public function test_payment_validations(): void
    {
        $this->suscripcion();

        $this->pagar(['monto' => '0', 'metodo' => 'Trueque', 'periodo_hasta' => '2026-11-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['monto', 'metodo', 'periodo_hasta']);

        $this->pagar(['monto' => '10.555', 'periodo_desde' => 'ayer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['monto', 'periodo_desde']);

        // Un año mal escrito no extiende el vencimiento por décadas.
        $this->pagar(['periodo_hasta' => '2096-12-20'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('periodo_hasta');
    }

    public function test_a_local_without_subscription_cannot_register_payments(): void
    {
        $this->pagar()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pago' => 'Este local no tiene suscripción. Asígnale un plan primero.']);
    }

    public function test_changing_plan_keeps_due_date_and_state_and_sets_the_agreed_price(): void
    {
        $suscripcion = $this->suscripcion();
        $premium = Plan::create(['nombre' => 'Premium', 'precio_mensual' => 129]);

        $this->putJson(route('panel.superadmin.restaurantes.plan', $this->restaurante), ['plan_id' => $premium->id, 'precio_pactado' => '110,50'])->assertOk();

        $suscripcion->refresh();
        $this->assertSame($premium->id, $suscripcion->plan_id);
        $this->assertSame('110.50', $suscripcion->precio_pactado);
        $this->assertSame('activa', $suscripcion->estado);
        $this->assertSame('2026-11-20', $suscripcion->vence_el->toDateString());
        $this->assertSame($premium->id, $this->restaurante->fresh()->plan_id);
    }

    public function test_assigning_a_plan_to_a_local_without_subscription_starts_a_trial(): void
    {
        $this->putJson(route('panel.superadmin.restaurantes.plan', $this->restaurante), ['plan_id' => $this->plan->id, 'precio_pactado' => '0'])->assertOk();

        $suscripcion = Suscripcion::where('restaurante_id', $this->restaurante->id)->sole();
        $this->assertSame('prueba', $suscripcion->estado);
        $this->assertSame('2026-12-10', $suscripcion->vence_el->toDateString());
    }

    public function test_cancelling_a_subscription(): void
    {
        $suscripcion = $this->suscripcion();

        $this->patchJson(route('panel.superadmin.restaurantes.cancelar', $this->restaurante), ['motivo' => 'Dejó de usar la plataforma'])->assertOk();
        $this->assertSame('cancelada', $suscripcion->fresh()->estado);
        $this->assertFalse($this->restaurante->fresh()->operativo());

        $this->patchJson(route('panel.superadmin.restaurantes.cancelar', $this->restaurante), ['motivo' => 'Otra vez cancelada'])
            ->assertUnprocessable();
    }
}
