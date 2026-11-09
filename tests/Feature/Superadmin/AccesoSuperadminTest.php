<?php

namespace Tests\Feature\Superadmin;

use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\User;
use Database\Seeders\MetodoPagoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccesoSuperadminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesSinAcceso(): array
    {
        return [
            'admin' => ['admin'],
            'cajero' => ['cajero'],
            'cocina' => ['cocina'],
            'mesero' => ['mesero'],
            'repartidor' => ['repartidor'],
        ];
    }

    #[DataProvider('rolesSinAcceso')]
    public function test_no_other_role_reaches_any_superadmin_route(string $rol): void
    {
        $propio = Restaurante::factory()->create();
        $usuario = User::factory()->personal($rol, $propio)->create();
        $this->actingAs($usuario);

        foreach (Route::getRoutes() as $ruta) {
            if (! str_starts_with((string) $ruta->getName(), 'panel.superadmin.')) {
                continue;
            }

            $url = str_replace(['{restaurante}', '{pago}'], [(string) $propio->id, '1'], $ruta->uri());
            $metodo = $ruta->methods()[0];

            $this->json($metodo, '/'.$url)->assertForbidden();
        }
    }

    public function test_guests_go_to_login(): void
    {
        $this->get(route('panel.superadmin.resumen'))->assertRedirect(route('login'));
    }

    public function test_customers_get_forbidden(): void
    {
        $this->actingAs(User::factory()->cliente()->create())
            ->get(route('panel.superadmin.resumen'))
            ->assertForbidden();
    }

    public function test_the_response_does_not_reveal_which_ids_exist(): void
    {
        $this->actingAs(User::factory()->admin(Restaurante::factory()->create())->create())
            ->get('/panel/superadmin/restaurantes/999999')
            ->assertForbidden();

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/panel/superadmin/restaurantes/999999')
            ->assertNotFound();
    }

    public function test_the_sidebar_is_the_superadmin_one(): void
    {
        $restaurante = Restaurante::factory()->create();

        $this->actingAs(User::factory()->admin($restaurante)->create())
            ->get(route('panel.inicio'))
            ->assertDontSee(route('panel.superadmin.resumen'));

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(route('panel.superadmin.restaurantes.index'))
            ->assertSee(route('panel.superadmin.resumen'))
            ->assertDontSee(route('panel.carta.productos.index'));
    }

    public function test_a_local_admin_cannot_change_plan_state_or_due_date_by_any_route(): void
    {
        $this->seed(MetodoPagoSeeder::class);

        $plan = Plan::create(['nombre' => 'Básico', 'precio_mensual' => 39]);
        $premium = Plan::create(['nombre' => 'Premium', 'precio_mensual' => 129]);
        $restaurante = Restaurante::factory()->create(['plan_id' => $plan->id, 'nombre' => 'Mi local', 'ciudad' => 'Huánuco']);
        $suscripcion = Suscripcion::factory()->create(['restaurante_id' => $restaurante->id, 'plan_id' => $plan->id]);
        $vence = $suscripcion->vence_el->toDateString();
        $admin = User::factory()->admin($restaurante)->create();

        $intruso = [
            'plan_id' => $premium->id,
            'activo' => '0',
            'vence_el' => '2099-01-01',
            'estado' => 'activa',
            'precio_pactado' => '0',
            'notas_internas' => 'hackeado',
            'slug' => 'otro-slug',
        ];

        $this->actingAs($admin);

        $this->putJson(route('panel.configuracion.general.update'), ['nombre' => 'Mi local', 'ciudad' => 'Huánuco', ...$intruso])->assertOk();
        $this->putJson(route('panel.configuracion.pedidos.update'), [
            'acepta_delivery' => '1', 'acepta_recojo' => '1', 'costo_delivery' => '0', 'pedido_minimo' => '0', ...$intruso,
        ])->assertOk();
        $this->putJson(route('panel.configuracion.notificaciones.update'), $intruso)->assertOk();
        $this->patchJson(route('panel.estado-local'), [...$intruso, 'estado' => 'abierto'])->assertOk();
        $this->putJson(route('panel.superadmin.restaurantes.plan', $restaurante), ['plan_id' => $premium->id, 'precio_pactado' => 0])->assertForbidden();
        $this->postJson(route('panel.superadmin.restaurantes.pagos.store', $restaurante), [
            'monto' => 1, 'metodo' => 'Yape', 'periodo_desde' => '2026-01-01', 'periodo_hasta' => '2099-01-01',
        ])->assertForbidden();
        $this->patchJson(route('panel.superadmin.restaurantes.reactivar', $restaurante), ['motivo' => 'Porque sí'])->assertForbidden();

        $fresco = $restaurante->fresh();
        $this->assertSame($plan->id, $fresco->plan_id);
        $this->assertTrue($fresco->activo);
        $this->assertNull($fresco->notas_internas);
        $this->assertSame($restaurante->slug, $fresco->slug);

        $suscripcion->refresh();
        $this->assertSame($plan->id, $suscripcion->plan_id);
        $this->assertSame($vence, $suscripcion->vence_el->toDateString());
        $this->assertSame('39.00', $suscripcion->precio_pactado);
    }

    public function test_internal_notes_never_reach_the_local(): void
    {
        $restaurante = Restaurante::factory()->create();
        $restaurante->forceFill(['notas_internas' => 'Debe dos meses, ojo con este local'])->save();
        $admin = User::factory()->admin($restaurante)->create();

        $this->assertArrayNotHasKey('notas_internas', $restaurante->fresh()->toArray());

        $this->actingAs($admin);

        foreach (['panel.inicio', 'panel.suscripcion', 'panel.configuracion.general', 'panel.configuracion.pedidos', 'panel.mi-cuenta'] as $ruta) {
            $this->get(route($ruta))->assertOk()->assertDontSee('Debe dos meses');
        }

        $this->app['auth']->forgetGuards();

        $this->get(route('vitrina.show', $restaurante->slug))->assertOk()->assertDontSee('Debe dos meses');
    }

    public function test_only_the_local_admin_consults_my_subscription(): void
    {
        $restaurante = Restaurante::factory()->create();
        Suscripcion::factory()->create(['restaurante_id' => $restaurante->id]);

        $this->actingAs(User::factory()->admin($restaurante)->create())
            ->get(route('panel.suscripcion'))
            ->assertOk()
            ->assertSee('Mi suscripción')
            ->assertSee('Activa');

        $this->actingAs(User::factory()->cajero($restaurante)->create())
            ->get(route('panel.suscripcion'))
            ->assertForbidden();

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(route('panel.suscripcion'))
            ->assertForbidden();
    }
}
