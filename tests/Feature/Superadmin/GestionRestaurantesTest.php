<?php

namespace Tests\Feature\Superadmin;

use App\Models\ActividadLog;
use App\Models\Pedido;
use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\Rubro;
use App\Models\Suscripcion;
use App\Models\SuscripcionPago;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Database\Seeders\MetodoPagoSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GestionRestaurantesTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'America/Lima'));
        $this->superadmin = User::factory()->superadmin()->create();
        $this->actingAs($this->superadmin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @param  array<string, mixed>  $local
     */
    private function local(array $atributos = [], array $local = []): Restaurante
    {
        $restaurante = Restaurante::factory()->create($local);
        Suscripcion::factory()->create(['restaurante_id' => $restaurante->id, ...$atributos]);

        return $restaurante;
    }

    public function test_summary_counts_each_state_and_the_recurring_income(): void
    {
        $this->local(['estado' => 'activa', 'precio_pactado' => 79, 'vence_el' => '2026-12-30']);
        $this->local(['estado' => 'activa', 'precio_pactado' => 39, 'vence_el' => '2026-11-15']);
        $this->local(['estado' => 'prueba', 'precio_pactado' => 0, 'vence_el' => '2026-11-12']);
        $this->local(['estado' => 'prueba', 'precio_pactado' => 0, 'vence_el' => '2026-12-30']);
        $this->local(['estado' => 'vencida', 'precio_pactado' => 79, 'vence_el' => '2026-11-08']);
        $this->local(['estado' => 'activa', 'precio_pactado' => 129, 'vence_el' => '2026-11-01']); // venció, el comando no ha corrido
        $this->local(['estado' => 'cancelada', 'precio_pactado' => 79, 'vence_el' => '2026-12-30']);
        $this->local(['estado' => 'activa', 'precio_pactado' => 500, 'vence_el' => '2026-12-30'], ['activo' => false]);
        Restaurante::factory()->count(2)->create();

        $vista = $this->get(route('panel.superadmin.resumen'))
            ->assertOk()
            ->assertSee('Locales sin suscripción')
            ->assertSee('Ingreso mensual estimado');

        $vista->assertViewHasAll([
            'activos' => 2,
            'enPrueba' => 2,
            'porVencer' => 2,
            'vencidos' => 2,
            'sinSuscripcion' => 2,
            'suspendidos' => 1,
            'ingresoMensual' => 118.0,
        ]);
    }

    public function test_summary_lists_the_latest_payments(): void
    {
        $restaurante = $this->local(['estado' => 'activa'], ['nombre' => 'Chifa Pagador']);
        SuscripcionPago::create([
            'suscripcion_id' => $restaurante->suscripcionActual->id, 'monto' => 79, 'metodo' => 'Yape',
            'periodo_desde' => '2026-11-01', 'periodo_hasta' => '2026-11-30', 'pagado_at' => now(),
        ]);

        $this->get(route('panel.superadmin.resumen'))->assertSee('Chifa Pagador')->assertSee('S/&nbsp;79.00', false);
    }

    public function test_summary_is_fine_when_empty(): void
    {
        $this->get(route('panel.superadmin.resumen'))->assertOk()->assertSee('Todavía no se ha registrado ningún pago.');
    }

    public function test_the_list_shows_every_column(): void
    {
        $rubro = Rubro::create(['nombre' => 'Chifa']);
        $plan = Plan::create(['nombre' => 'Pro', 'precio_mensual' => 79]);
        $restaurante = $this->local(['plan_id' => $plan->id, 'vence_el' => '2026-12-30'], ['nombre' => 'Chifa Listado', 'ciudad' => 'Tingo María', 'rubro_id' => $rubro->id, 'plan_id' => $plan->id]);

        Pedido::factory()->count(2)->create(['restaurante_id' => $restaurante->id, 'created_at' => '2026-11-05 12:00:00']);
        Pedido::factory()->create(['restaurante_id' => $restaurante->id, 'created_at' => '2026-10-20 12:00:00']);

        $this->get(route('panel.superadmin.restaurantes.index'))
            ->assertOk()
            ->assertSee('Chifa Listado')
            ->assertSee('Tingo María')
            ->assertSee('Chifa')
            ->assertSee('Pro')
            ->assertSee('Activa')
            ->assertSee('30/12/2026')
            ->assertViewHas('restaurantes', fn ($lista): bool => $lista->first()->pedidos_mes === 2);
    }

    public function test_the_month_of_orders_starts_in_lima(): void
    {
        $restaurante = $this->local();

        // El 1 de noviembre a las 02:00 UTC todavía es octubre en Lima.
        Pedido::factory()->create(['restaurante_id' => $restaurante->id, 'created_at' => '2026-11-01 02:00:00']);
        Pedido::factory()->create(['restaurante_id' => $restaurante->id, 'created_at' => '2026-11-01 06:00:00']);

        $this->get(route('panel.superadmin.restaurantes.index'))
            ->assertViewHas('restaurantes', fn ($lista): bool => $lista->first()->pedidos_mes === 1);
    }

    public function test_search_and_filters(): void
    {
        $pro = Plan::create(['nombre' => 'Pro', 'precio_mensual' => 79]);
        $basico = Plan::create(['nombre' => 'Básico', 'precio_mensual' => 39]);

        $this->local(['estado' => 'activa', 'plan_id' => $pro->id, 'vence_el' => '2026-12-30'], ['nombre' => 'Local Activo', 'ciudad' => 'Huánuco', 'plan_id' => $pro->id]);
        $this->local(['estado' => 'prueba', 'plan_id' => $basico->id, 'vence_el' => '2026-12-30'], ['nombre' => 'Local Prueba', 'ciudad' => 'Tingo María', 'plan_id' => $basico->id]);
        $this->local(['estado' => 'activa', 'vence_el' => '2026-11-01'], ['nombre' => 'Local Vencido', 'ciudad' => 'Huánuco']);
        $this->local(['estado' => 'cancelada'], ['nombre' => 'Local Cancelado', 'ciudad' => 'Huánuco']);
        $this->local(['estado' => 'activa', 'vence_el' => '2026-12-30'], ['nombre' => 'Local Suspendido', 'ciudad' => 'Huánuco', 'activo' => false]);
        Restaurante::factory()->create(['nombre' => 'Local Sin Nada', 'ciudad' => 'Huánuco']);

        $nombres = fn (array $filtros): array => $this->get(route('panel.superadmin.restaurantes.index', $filtros))
            ->assertOk()
            ->viewData('restaurantes')->pluck('nombre')->all();

        $this->assertSame(['Local Prueba'], $nombres(['q' => 'prueba']));
        $this->assertSame(['Local Prueba'], $nombres(['ciudad' => 'Tingo María']));
        $this->assertSame(['Local Activo'], $nombres(['plan' => $pro->id]));
        $this->assertSame(['Local Prueba'], $nombres(['estado' => 'prueba']));
        $this->assertSame(['Local Activo', 'Local Suspendido'], $nombres(['estado' => 'activa']));
        $this->assertSame(['Local Vencido'], $nombres(['estado' => 'vencida']));
        $this->assertSame(['Local Cancelado'], $nombres(['estado' => 'cancelada']));
        $this->assertSame(['Local Suspendido'], $nombres(['estado' => 'suspendidos']));
        $this->assertSame(['Local Sin Nada'], $nombres(['estado' => 'sin_suscripcion']));
        $this->assertSame([], $nombres(['q' => '%']));

        $this->get(route('panel.superadmin.restaurantes.index', ['q' => 'no-existe']))
            ->assertSee('Ningún restaurante coincide con estos filtros.');
    }

    public function test_the_list_is_paginated_by_20_and_empty_state_invites_to_create(): void
    {
        $this->get(route('panel.superadmin.restaurantes.index'))->assertSee('Todavía no hay restaurantes.');

        Restaurante::factory()->count(21)->create();

        $this->get(route('panel.superadmin.restaurantes.index'))
            ->assertViewHas('restaurantes', fn ($lista): bool => $lista->count() === 20 && $lista->total() === 21)
            ->assertSee('Siguiente');
    }

    public function test_the_detail_shows_everything_and_escapes_text(): void
    {
        $restaurante = $this->local(['estado' => 'activa'], ['nombre' => '<script>alert(1)</script>']);
        $restaurante->forceFill(['notas_internas' => 'Nota <b>privada</b>'])->save();
        User::factory()->admin($restaurante)->create(['name' => 'Rosa Admin']);

        $this->get(route('panel.superadmin.restaurantes.show', $restaurante))
            ->assertOk()
            ->assertSee('Rosa Admin')
            ->assertSee('Registrar pago')
            ->assertSee('Nota &lt;b&gt;privada&lt;/b&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_editing_data_never_changes_the_slug(): void
    {
        $restaurante = $this->local([], ['slug' => 'slug-fijo']);

        $this->putJson(route('panel.superadmin.restaurantes.update', $restaurante), [
            'nombre' => 'Nombre Nuevo', 'ciudad' => 'Lima', 'telefono' => '987654321', 'slug' => 'otro-slug', 'activo' => '0', 'plan_id' => 999,
        ])->assertOk();

        $fresco = $restaurante->fresh();
        $this->assertSame('Nombre Nuevo', $fresco->nombre);
        $this->assertSame('slug-fijo', $fresco->slug);
        $this->assertTrue($fresco->activo);
        $this->assertNull($fresco->plan_id);

        $this->putJson(route('panel.superadmin.restaurantes.update', $restaurante), ['nombre' => '', 'ciudad' => '', 'telefono' => '1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'ciudad', 'telefono']);
    }

    public function test_internal_notes_are_saved_but_their_content_is_not_audited(): void
    {
        $restaurante = $this->local();

        $this->putJson(route('panel.superadmin.restaurantes.notas', $restaurante), ['notas_internas' => 'Dueño difícil'])->assertOk();

        $this->assertSame('Dueño difícil', $restaurante->fresh()->notas_internas);

        $log = ActividadLog::where('accion', 'edito_notas')->sole();
        $this->assertStringNotContainsString('Dueño difícil', json_encode($log->getAttributes()));
    }

    public function test_suspending_and_reactivating_need_a_reason_and_are_audited(): void
    {
        $restaurante = $this->local();

        $this->patchJson(route('panel.superadmin.restaurantes.suspender', $restaurante), ['motivo' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo');
        $this->assertTrue($restaurante->fresh()->activo);

        $this->patchJson(route('panel.superadmin.restaurantes.suspender', $restaurante), ['motivo' => 'Pago rechazado'])->assertOk();
        $this->assertFalse($restaurante->fresh()->activo);
        $this->assertFalse($restaurante->fresh()->operativo());

        $this->patchJson(route('panel.superadmin.restaurantes.suspender', $restaurante), ['motivo' => 'Otra vez suspendido'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['motivo' => 'El local ya estaba suspendido.']);

        $this->patchJson(route('panel.superadmin.restaurantes.reactivar', $restaurante), ['motivo' => 'Ya regularizó'])->assertOk();
        $this->assertTrue($restaurante->fresh()->activo);
        $this->assertTrue($restaurante->fresh()->operativo());

        $suspension = ActividadLog::where('accion', 'suspendio_local')->sole();
        $this->assertSame($restaurante->id, $suspension->restaurante_id);
        $this->assertSame($this->superadmin->id, $suspension->user_id);
        $this->assertSame(['activo' => true], $suspension->datos_antes);
        $this->assertSame(['activo' => false, 'motivo' => 'Pago rechazado'], $suspension->datos_despues);
        $this->assertSame(1, ActividadLog::where('accion', 'reactivo_local')->count());
    }

    public function test_every_action_is_audited_without_secrets(): void
    {
        $this->seed([PlanSeeder::class, MetodoPagoSeeder::class]);
        $plan = Plan::where('nombre', 'Pro')->sole();
        $premium = Plan::where('nombre', 'Premium')->sole();

        $temporal = $this->withServerVariables(['REMOTE_ADDR' => '181.65.10.20'])
            ->postJson(route('panel.superadmin.restaurantes.store'), [
                'nombre' => 'Auditado', 'slug' => 'auditado', 'ciudad' => 'Huánuco', 'plan_id' => $plan->id,
                'admin_nombre' => 'Lina', 'admin_email' => 'lina@auditado.pe',
            ])->assertCreated()->json('password_temporal');

        $restaurante = Restaurante::where('slug', 'auditado')->sole();
        $admin = User::where('email', 'lina@auditado.pe')->sole();

        $this->putJson(route('panel.superadmin.restaurantes.plan', $restaurante), ['plan_id' => $premium->id, 'precio_pactado' => '129'])->assertOk();
        $this->postJson(route('panel.superadmin.restaurantes.pagos.store', $restaurante), [
            'monto' => '129', 'metodo' => 'Yape', 'referencia' => 'OP-9', 'periodo_desde' => '2026-12-11', 'periodo_hasta' => '2027-01-10',
        ])->assertOk();
        $this->patchJson(route('panel.superadmin.restaurantes.pagos.anular', [$restaurante, SuscripcionPago::sole()->id]), ['motivo' => 'Error de tipeo'])->assertOk();
        $this->patchJson(route('panel.superadmin.restaurantes.suspender', $restaurante), ['motivo' => 'Prueba de auditoría'])->assertOk();

        $logs = ActividadLog::where('restaurante_id', $restaurante->id)->orderBy('id')->get();

        $this->assertSame(['alta_restaurante', 'cambio_plan', 'registro_pago', 'anulo_pago', 'suspendio_local'], $logs->pluck('accion')->all());
        $this->assertTrue($logs->every(fn (ActividadLog $log): bool => $log->user_id === $this->superadmin->id && $log->modulo === 'suscripciones' && $log->ip !== null));
        $this->assertSame('181.65.10.20', $logs[0]->ip);

        $this->assertSame($plan->id, $logs[1]->datos_antes['plan_id']);
        $this->assertSame($premium->id, $logs[1]->datos_despues['plan_id']);
        $this->assertSame('2026-12-10', $logs[2]->datos_antes['vence_el']);
        $this->assertSame('2027-01-10', $logs[2]->datos_despues['vence_el']);
        $this->assertSame('activa', $logs[3]->datos_antes['estado']);
        $this->assertSame('prueba', $logs[3]->datos_despues['estado']);

        $todo = $logs->toJson().json_encode($logs->map->getAttributes());
        foreach ([$temporal, $admin->password, '"password":', '"remember_token":', '"token":'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $todo);
        }
    }

    public function test_demo_seeder_leaves_the_four_subscription_states_and_stays_blocked_in_production(): void
    {
        Carbon::setTestNow();

        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $estado = fn (string $slug): Restaurante => Restaurante::with('suscripcionActual')->where('slug', $slug)->sole();

        $prueba = $estado('cafe-la-prueba');
        $this->assertSame('prueba', $prueba->estadoSuscripcion());
        $this->assertLessThanOrEqual(Suscripcion::DIAS_AVISO, $prueba->diasParaVencer());
        $this->assertTrue($prueba->operativo());

        $activo = $estado('burger-al-dia');
        $this->assertSame('activa', $activo->estadoSuscripcion());
        $this->assertSame(2, SuscripcionPago::where('suscripcion_id', $activo->suscripcionActual->id)->count());

        $gracia = $estado('pollos-en-gracia');
        $this->assertTrue($gracia->enGracia());
        $this->assertSame(1, SuscripcionPago::where('suscripcion_id', $gracia->suscripcionActual->id)->count());

        $fuera = $estado('cevicheria-fuera');
        $this->assertFalse($fuera->operativo());

        foreach (['el-buen-sabor', 'la-bahia', 'chifa-dragon-dorado'] as $slug) {
            $this->assertTrue($estado($slug)->operativo());
        }

        $antes = Restaurante::count();
        Restaurante::where('slug', 'cafe-la-prueba')->forceDelete();
        $this->app->detectEnvironment(fn (): string => 'production');
        app(DemoSeeder::class)->run();
        $this->assertSame($antes - 1, Restaurante::count());
    }
}
