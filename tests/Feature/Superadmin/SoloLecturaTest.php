<?php

namespace Tests\Feature\Superadmin;

use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\MetodoPagoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoloLecturaTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'America/Lima'));

        $this->seed(MetodoPagoSeeder::class);
        $this->restaurante = Restaurante::factory()->create(['nombre' => 'Original']);
        $this->admin = User::factory()->admin($this->restaurante)->create();
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function vencerFueraDeGracia(): void
    {
        Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'vencida', 'vence_el' => '2026-10-01']);
    }

    public function test_out_of_operation_blocks_writes_to_everything_else(): void
    {
        $this->vencerFueraDeGracia();
        $mensaje = 'Tu local está fuera de operación: solo puedes ver la información y terminar los pedidos en curso. Contacta a FOORA.';

        $this->putJson(route('panel.configuracion.general.update'), ['nombre' => 'Cambiado', 'ciudad' => 'Lima'])
            ->assertForbidden()
            ->assertJsonPath('message', $mensaje);
        $this->putJson(route('panel.configuracion.horario.update'), ['dias' => []])->assertForbidden();
        $this->putJson(route('panel.configuracion.pago.update'), ['metodos' => []])->assertForbidden();
        $this->postJson(route('panel.configuracion.usuarios.store'), ['name' => 'X', 'email' => 'x@x.pe', 'rol' => 'cajero'])->assertForbidden();
        $this->postJson(route('panel.carta.categorias.store'), ['nombre' => 'Bebidas'])->assertForbidden();
        $this->postJson(route('panel.carta.productos.store'), ['nombre' => 'Lomo'])->assertForbidden();

        $this->assertSame('Original', $this->restaurante->fresh()->nombre);
        $this->assertDatabaseMissing('users', ['email' => 'x@x.pe']);
        $this->assertDatabaseMissing('categorias_producto', ['nombre' => 'Bebidas']);
    }

    public function test_without_javascript_it_goes_back_with_the_message(): void
    {
        $this->vencerFueraDeGracia();

        $this->from(route('panel.configuracion.general'))
            ->put(route('panel.configuracion.general.update'), ['nombre' => 'Cambiado', 'ciudad' => 'Lima'])
            ->assertRedirect(route('panel.configuracion.general'))
            ->assertSessionHas('error');
    }

    public function test_it_can_still_read_everything(): void
    {
        $this->vencerFueraDeGracia();

        foreach (['panel.inicio', 'panel.pedidos.index', 'panel.carta.productos.index', 'panel.configuracion.general', 'panel.configuracion.usuarios', 'panel.suscripcion', 'panel.mi-cuenta'] as $ruta) {
            $this->get(route($ruta))->assertOk();
        }

        $this->getJson(route('panel.pedidos.novedades', ['desde' => 0]))->assertOk();
    }

    public function test_existing_orders_can_be_finished(): void
    {
        $this->vencerFueraDeGracia();
        $yape = MetodoPago::where('codigo', 'yape')->sole();

        $pedido = Pedido::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'nuevo']);
        $this->patchJson(route('panel.pedidos.estado', $pedido), ['desde' => 'nuevo', 'destino' => 'en_preparacion'])->assertOk();
        $this->assertSame('en_preparacion', $pedido->fresh()->estado);

        $porValidar = Pedido::factory()->create(['restaurante_id' => $this->restaurante->id, 'metodo_pago_id' => $yape->id, 'estado_pago' => 'por_verificar']);
        $this->patchJson(route('panel.pedidos.pago', $porValidar), ['accion' => 'validar'])->assertOk();
        $this->assertSame('pagado', $porValidar->fresh()->estado_pago);

        $porRechazar = Pedido::factory()->create(['restaurante_id' => $this->restaurante->id, 'metodo_pago_id' => $yape->id, 'estado_pago' => 'por_verificar']);
        $this->patchJson(route('panel.pedidos.pago', $porRechazar), ['accion' => 'rechazar'])->assertOk();
        $this->assertSame('rechazado', $porRechazar->fresh()->estado_pago);
    }

    public function test_the_open_close_switch_keeps_working_but_opening_does_not_bring_the_storefront_back(): void
    {
        $this->vencerFueraDeGracia();

        $this->patchJson(route('panel.estado-local'), ['estado' => 'cerrado', 'confirmar' => '1'])->assertOk();
        $this->assertSame('cerrado', $this->restaurante->fresh()->estado_local);

        $this->patchJson(route('panel.estado-local'), ['estado' => 'abierto'])->assertOk();
        $this->assertSame('abierto', $this->restaurante->fresh()->estado_local);
        $this->assertFalse($this->restaurante->fresh()->operativo());
    }

    public function test_own_account_can_still_be_managed(): void
    {
        $this->vencerFueraDeGracia();

        $this->putJson(route('panel.mi-cuenta.password'), [
            'password_actual' => 'password', 'password' => 'clave-nueva-1', 'password_confirmation' => 'clave-nueva-1',
        ])->assertOk();
    }

    public function test_suspension_is_read_only_too_but_within_grace_is_not(): void
    {
        $this->restaurante->update(['activo' => false]);
        $this->admin->unsetRelation('restaurante');
        $this->putJson(route('panel.configuracion.general.update'), ['nombre' => 'Cambiado', 'ciudad' => 'Lima'])->assertForbidden();

        $this->restaurante->update(['activo' => true]);
        $this->admin->unsetRelation('restaurante');
        Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'vencida', 'vence_el' => '2026-11-08']);

        $this->putJson(route('panel.configuracion.general.update'), ['nombre' => 'Cambiado', 'ciudad' => 'Lima'])->assertOk();
    }

    public function test_notice_when_7_days_or_less_remain(): void
    {
        $suscripcion = Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'activa', 'vence_el' => '2026-11-18']);

        $this->get(route('panel.inicio'))->assertOk()->assertDontSee('data-aviso-suscripcion', false);

        $suscripcion->update(['vence_el' => '2026-11-17']);
        $this->admin->unsetRelation('restaurante');
        $this->get(route('panel.inicio'))->assertSee('Tu suscripción vence en 7 días');

        $suscripcion->update(['vence_el' => '2026-11-11']);
        $this->admin->unsetRelation('restaurante');
        $this->get(route('panel.inicio'))->assertSee('Tu suscripción vence mañana');

        $suscripcion->update(['vence_el' => '2026-11-10', 'estado' => 'prueba']);
        $this->admin->unsetRelation('restaurante');
        $this->get(route('panel.inicio'))->assertSee('Tu prueba gratis vence hoy');
    }

    public function test_grace_notice_counts_the_remaining_days(): void
    {
        $suscripcion = Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'vencida', 'vence_el' => '2026-11-08']);

        $this->get(route('panel.inicio'))->assertSee('Te quedan 3 días de gracia');

        $suscripcion->update(['vence_el' => '2026-11-06']);
        $this->admin->unsetRelation('restaurante');
        $this->get(route('panel.inicio'))->assertSee('Te queda 1 día de gracia');

        $suscripcion->update(['vence_el' => '2026-11-05']);
        $this->admin->unsetRelation('restaurante');
        $this->get(route('panel.inicio'))->assertSee('Hoy es el último día de gracia');
    }

    public function test_out_of_operation_notice_asks_to_contact_foora_for_every_role(): void
    {
        $this->vencerFueraDeGracia();

        $this->get(route('panel.inicio'))->assertSee('Tu suscripción venció')->assertSee('Contacta a FOORA');

        $this->actingAs(User::factory()->cajero($this->restaurante)->create())
            ->get(route('panel.inicio'))
            ->assertSee('Contacta a FOORA')
            ->assertDontSee('Ver mi suscripción');
    }

    public function test_staff_of_a_suspended_local_can_still_log_in(): void
    {
        $this->restaurante->update(['activo' => false]);
        $this->post(route('logout'));
        $this->app['auth']->forgetGuards();

        $this->post(route('login.store'), ['email' => $this->admin->email, 'password' => 'password'])
            ->assertRedirect(route('panel.inicio'));

        $this->assertAuthenticatedAs($this->admin);
    }
}
