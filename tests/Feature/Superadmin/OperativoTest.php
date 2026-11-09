<?php

namespace Tests\Feature\Superadmin;

use App\Models\CategoriaProducto;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OperativoTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private Producto $producto;

    private MetodoPago $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'America/Lima'));

        $this->restaurante = Restaurante::factory()->create(['slug' => 'polleria-central', 'estado_local' => 'abierto', 'nombre' => 'Pollería Central']);
        $categoria = CategoriaProducto::factory()->for($this->restaurante)->create();
        $this->producto = Producto::factory()->for($categoria, 'categoria')->create(['nombre' => 'Anticuchos', 'precio' => 30]);
        $this->efectivo = MetodoPago::create(['codigo' => 'efectivo', 'nombre' => 'Efectivo']);
        $this->restaurante->metodosPago()->attach($this->efectivo, ['activo' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function suscripcion(string $vence, string $estado = 'activa'): Suscripcion
    {
        return Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => $estado, 'vence_el' => $vence]);
    }

    private function validarCarrito(): TestResponse
    {
        return $this->postJson(route('vitrina.carrito.validar', 'polleria-central'), [
            'lineas' => [['producto_id' => $this->producto->id, 'variante_id' => null, 'opciones' => [], 'cantidad' => 1]],
        ]);
    }

    private function pedir(): TestResponse
    {
        return $this->postJson(route('vitrina.pedido.store', 'polleria-central'), [
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
            'lineas' => [['producto_id' => $this->producto->id, 'variante_id' => null, 'opciones' => [], 'cantidad' => 1, 'observacion' => null]],
        ]);
    }

    private function assertNoDisponible(): void
    {
        $this->get(route('vitrina.show', 'polleria-central'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertSee('Este restaurante no está disponible por ahora')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('Anticuchos');

        $this->validarCarrito()->assertConflict()->assertJsonPath('message', 'Este restaurante no está disponible por ahora.');

        $antes = Pedido::count();
        $this->pedir()->assertConflict()->assertJsonPath('message', 'Este restaurante no está disponible por ahora.');
        $this->assertSame($antes, Pedido::count());
    }

    private function assertDisponible(): void
    {
        $this->get(route('vitrina.show', 'polleria-central'))->assertOk()->assertSee('Anticuchos')->assertDontSee('no está disponible por ahora');
        $this->validarCarrito()->assertOk();
        $this->pedir()->assertCreated();
    }

    public function test_a_local_without_subscription_operates(): void
    {
        $this->assertTrue($this->restaurante->operativo());
        $this->assertDisponible();
    }

    public function test_a_current_subscription_operates(): void
    {
        $this->suscripcion('2026-12-10');
        $this->assertDisponible();
    }

    public function test_within_grace_it_keeps_operating_even_marked_as_expired(): void
    {
        $this->suscripcion('2026-11-05', 'vencida');

        $restaurante = $this->restaurante->fresh();
        $this->assertTrue($restaurante->operativo());
        $this->assertTrue($restaurante->enGracia());
        $this->assertDisponible();
    }

    public function test_past_grace_it_is_not_available_even_if_the_command_never_ran(): void
    {
        $this->suscripcion('2026-11-04', 'activa');

        $this->assertFalse($this->restaurante->fresh()->operativo());
        $this->assertNoDisponible();
    }

    public function test_a_suspended_local_is_not_available(): void
    {
        $this->suscripcion('2026-12-10');
        $this->restaurante->update(['activo' => false]);

        $this->assertNoDisponible();
    }

    public function test_a_cancelled_subscription_is_not_available_without_grace(): void
    {
        $this->suscripcion('2026-12-10', 'cancelada');

        $this->assertNoDisponible();
    }

    public function test_the_latest_subscription_is_the_one_that_counts(): void
    {
        $this->suscripcion('2026-01-01', 'cancelada');
        $this->suscripcion('2026-12-10');

        $this->assertTrue($this->restaurante->fresh()->operativo());
    }

    public function test_the_midnight_that_counts_is_lima(): void
    {
        $this->suscripcion('2026-11-05');

        // Último día de gracia (10): a las 23:59 de Lima en UTC ya es el 11.
        Carbon::setTestNow(Carbon::parse('2026-11-10 23:59', 'America/Lima'));
        $this->assertSame('2026-11-11', now('UTC')->toDateString());
        $this->assertSame('2026-11-10', $this->restaurante->fresh()->fechaHoy());
        $this->assertTrue($this->restaurante->fresh()->operativo());

        Carbon::setTestNow(Carbon::parse('2026-11-11 00:00', 'America/Lima'));
        $this->assertFalse($this->restaurante->fresh()->operativo());
    }

    public function test_days_to_expire_use_the_lima_date(): void
    {
        $this->suscripcion('2026-11-20');

        Carbon::setTestNow(Carbon::parse('2026-11-19 23:30', 'America/Lima'));
        $this->assertSame(1, $this->restaurante->fresh()->diasParaVencer());

        Carbon::setTestNow(Carbon::parse('2026-11-20 00:30', 'America/Lima'));
        $this->assertSame(0, $this->restaurante->fresh()->diasParaVencer());

        Carbon::setTestNow(Carbon::parse('2026-11-21 00:30', 'America/Lima'));
        $this->assertSame(-1, $this->restaurante->fresh()->diasParaVencer());
    }

    public function test_public_tracking_stays_available_always(): void
    {
        $pedido = Pedido::factory()->create(['restaurante_id' => $this->restaurante->id, 'codigo_seguimiento' => 'CodigoDePrueba123']);

        foreach (['suspendido', 'vencido', 'cancelado'] as $caso) {
            match ($caso) {
                'suspendido' => $this->restaurante->update(['activo' => false]),
                'vencido' => $this->suscripcion('2026-01-01'),
                'cancelado' => $this->suscripcion('2026-12-10', 'cancelada'),
            };

            $this->get(route('vitrina.pedido.seguimiento', ['polleria-central', $pedido->codigo_seguimiento]))->assertOk();
            $this->getJson(route('vitrina.pedido.estado', ['polleria-central', $pedido->codigo_seguimiento]))->assertOk();
        }
    }

    public function test_repeating_an_order_already_created_returns_it_even_if_the_local_stopped_operating(): void
    {
        $clave = Str::uuid()->toString();
        $datos = [
            'nombre' => 'Ana Torres', 'telefono' => '987654321', 'tipo' => 'recojo', 'direccion' => null, 'referencia' => null,
            'metodo_pago_id' => $this->efectivo->id, 'referencia_pago' => null, 'acepta_privacidad' => true, 'sitio_web' => '',
            'idempotency_key' => $clave,
            'lineas' => [['producto_id' => $this->producto->id, 'variante_id' => null, 'opciones' => [], 'cantidad' => 1, 'observacion' => null]],
        ];

        $this->postJson(route('vitrina.pedido.store', 'polleria-central'), $datos)->assertCreated();
        $this->restaurante->update(['activo' => false]);
        $this->postJson(route('vitrina.pedido.store', 'polleria-central'), $datos)->assertOk();

        $this->assertSame(1, Pedido::count());
    }
}
