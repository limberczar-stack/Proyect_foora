<?php

namespace Tests\Feature\Superadmin;

use App\Models\ActividadLog;
use App\Models\Pedido;
use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\SuscripcionPago;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RutaLaravel;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Los motivos de suspensión, cancelación y anulación son del equipo de FOORA: el local y el público
 * solo ven un mensaje genérico.
 */
class MotivosInternosTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO_ANULACION = 'MOTIVO-SECRETO-ANULACION';

    private const MOTIVO_CANCELACION = 'MOTIVO-SECRETO-CANCELACION';

    private const MOTIVO_SUSPENSION = 'MOTIVO-SECRETO-SUSPENSION';

    private const MOTIVOS = [self::MOTIVO_ANULACION, self::MOTIVO_CANCELACION, self::MOTIVO_SUSPENSION];

    private User $superadmin;

    private User $admin;

    private User $cajero;

    private Restaurante $restaurante;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'America/Lima'));

        $plan = Plan::create(['nombre' => 'Pro', 'precio_mensual' => 79]);
        $this->superadmin = User::factory()->superadmin()->create();
        $this->restaurante = Restaurante::factory()->create(['plan_id' => $plan->id]);
        $this->admin = User::factory()->admin($this->restaurante)->create();
        $this->cajero = User::factory()->cajero($this->restaurante)->create();

        Suscripcion::factory()->create([
            'restaurante_id' => $this->restaurante->id,
            'plan_id' => $plan->id,
            'estado' => 'activa',
            'vence_el' => '2026-11-20',
            'precio_pactado' => 79,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Registra y anula un pago, cancela la suscripción y suspende el local, cada uno con su motivo.
     */
    private function aplicarAccionesConMotivo(): void
    {
        $this->actingAs($this->superadmin);

        $this->postJson(route('panel.superadmin.restaurantes.pagos.store', $this->restaurante), [
            'monto' => '79.00', 'metodo' => 'Yape', 'referencia' => 'OP-1', 'periodo_desde' => '2026-11-21', 'periodo_hasta' => '2026-12-20',
        ])->assertOk();
        $this->patchJson(route('panel.superadmin.restaurantes.pagos.anular', [$this->restaurante, SuscripcionPago::sole()->id]), ['motivo' => self::MOTIVO_ANULACION])->assertOk();
        $this->patchJson(route('panel.superadmin.restaurantes.cancelar', $this->restaurante), ['motivo' => self::MOTIVO_CANCELACION])->assertOk();
        $this->patchJson(route('panel.superadmin.restaurantes.suspender', $this->restaurante), ['motivo' => self::MOTIVO_SUSPENSION])->assertOk();
    }

    private function assertSinMotivos(TestResponse $respuesta, string $donde): void
    {
        $this->assertLessThan(500, $respuesta->getStatusCode(), "{$donde} falló: la revisión no vale.");

        foreach (self::MOTIVOS as $motivo) {
            $this->assertStringNotContainsString($motivo, (string) $respuesta->getContent(), "{$donde} expone un motivo interno.");
        }
    }

    /**
     * Todas las pantallas GET del local, también las que se agreguen después (como Actividad).
     *
     * @return list<string>
     */
    private function rutasDelLocalSinParametros(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RutaLaravel $ruta): bool => in_array('GET', $ruta->methods(), true)
                && str_starts_with((string) $ruta->getName(), 'panel.')
                && ! str_starts_with((string) $ruta->getName(), 'panel.superadmin.')
                && $ruta->parameterNames() === [])
            ->map(fn (RutaLaravel $ruta): string => (string) $ruta->getName())
            ->values()
            ->all();
    }

    public function test_no_panel_screen_or_json_of_the_local_exposes_the_reasons(): void
    {
        $pedido = Pedido::factory()->create(['restaurante_id' => $this->restaurante->id]);
        $this->aplicarAccionesConMotivo();

        $rutas = $this->rutasDelLocalSinParametros();
        $this->assertContains('panel.suscripcion', $rutas);
        $this->assertContains('panel.pedidos.novedades', $rutas);

        foreach ([$this->admin, $this->cajero] as $usuario) {
            $this->actingAs($usuario->fresh());

            foreach ($rutas as $ruta) {
                $url = route($ruta, ['desde' => 0]);
                $this->assertSinMotivos($this->get($url), $ruta);
                $this->assertSinMotivos($this->getJson($url), "{$ruta} (JSON)");
            }

            $this->assertSinMotivos($this->get(route('panel.pedidos.show', $pedido)), 'panel.pedidos.show');
        }
    }

    public function test_the_local_only_sees_a_generic_message(): void
    {
        $this->aplicarAccionesConMotivo();
        $this->actingAs($this->admin->fresh());

        $this->get(route('panel.inicio'))->assertOk()->assertSee('Tu local está suspendido');
        $this->get(route('panel.suscripcion'))->assertOk()->assertDontSee('Motivo');

        $respuesta = $this->putJson(route('panel.configuracion.general.update'), ['nombre' => 'Cambiado', 'ciudad' => 'Lima'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Tu local está fuera de operación: solo puedes ver la información y terminar los pedidos en curso. Contacta a FOORA.');
        $this->assertSinMotivos($respuesta, 'el rechazo de escritura');
    }

    public function test_the_superadmin_section_stays_closed_to_the_local(): void
    {
        $this->aplicarAccionesConMotivo();
        $this->actingAs($this->admin->fresh());

        $respuesta = $this->get(route('panel.superadmin.restaurantes.show', $this->restaurante))->assertForbidden();

        $this->assertSinMotivos($respuesta, 'la ficha del superadmin');
    }

    public function test_public_responses_do_not_expose_the_reasons(): void
    {
        $pedido = Pedido::factory()->create(['restaurante_id' => $this->restaurante->id]);
        $this->aplicarAccionesConMotivo();
        $this->post(route('logout'));
        $this->app['auth']->forgetGuards();

        $this->assertSinMotivos(
            $this->get(route('vitrina.show', $this->restaurante->slug))->assertOk()->assertSee('no está disponible'),
            'la vitrina',
        );
        $this->assertSinMotivos(
            $this->postJson(route('vitrina.carrito.validar', $this->restaurante->slug), ['lineas' => []])
                ->assertConflict()
                ->assertExactJson(['message' => 'Este restaurante no está disponible por ahora.']),
            'la validación del carrito',
        );

        $codigo = ['slug' => $this->restaurante->slug, 'codigo' => $pedido->codigo_seguimiento];
        $this->assertSinMotivos($this->get(route('vitrina.pedido.seguimiento', $codigo))->assertOk(), 'el seguimiento');
        $this->assertSinMotivos($this->getJson(route('vitrina.pedido.estado', $codigo))->assertOk(), 'el estado del pedido');
    }

    public function test_a_voided_payment_does_not_serialize_its_reason(): void
    {
        $this->aplicarAccionesConMotivo();

        $pago = SuscripcionPago::sole();

        $this->assertSame(self::MOTIVO_ANULACION, $pago->motivo_anulacion);
        $this->assertArrayNotHasKey('motivo_anulacion', $pago->toArray());
        $this->assertStringNotContainsString(self::MOTIVO_ANULACION, $pago->toJson());
    }

    public function test_the_local_activity_never_includes_internal_records(): void
    {
        $this->aplicarAccionesConMotivo();

        $propia = ActividadLog::create([
            'restaurante_id' => $this->restaurante->id, 'user_id' => $this->admin->id,
            'modulo' => 'configuracion', 'accion' => 'edito_general', 'descripcion' => 'Editó los datos.',
        ]);

        $this->actingAs($this->admin->fresh());

        $this->assertSame([$propia->id], ActividadLog::pluck('id')->all());
        $this->assertSame([$propia->id], $this->restaurante->actividadLogs()->pluck('id')->all());
        $this->assertSame([$propia->id], ActividadLog::where('restaurante_id', $this->restaurante->id)->pluck('id')->all());

        $this->actingAs($this->superadmin);

        $this->assertSame(5, ActividadLog::where('restaurante_id', $this->restaurante->id)->count());
        $this->assertTrue(ActividadLog::where('accion', 'suspendio_local')->where('descripcion', 'like', '%'.self::MOTIVO_SUSPENSION.'%')->exists());
    }
}
