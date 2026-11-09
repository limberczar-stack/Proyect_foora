<?php

namespace Tests\Feature\Configuracion;

use App\Models\ActividadLog;
use App\Models\Pedido;
use App\Models\Restaurante;
use App\Models\User;
use Database\Seeders\MetodoPagoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EstadoLocalTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MetodoPagoSeeder::class);
        $this->restaurante = Restaurante::factory()->create(['estado_local' => 'abierto']);
    }

    public function test_closing_asks_for_confirmation_with_the_active_orders(): void
    {
        $admin = User::factory()->admin($this->restaurante)->create();
        Pedido::factory()->for($this->restaurante)->count(2)->create(['estado' => 'nuevo']);
        Pedido::factory()->for($this->restaurante)->create(['estado' => 'en_camino']);
        Pedido::factory()->for($this->restaurante)->create(['estado' => 'entregado']);
        Pedido::factory()->for(Restaurante::factory())->create(['estado' => 'nuevo']);

        $this->actingAs($admin)
            ->patchJson(route('panel.estado-local'), ['estado' => 'cerrado'])
            ->assertConflict()
            ->assertJsonPath('requiere_confirmacion', true)
            ->assertJsonPath('pedidos_activos', 3);

        $this->assertSame('abierto', $this->restaurante->fresh()->estado_local);

        $this->patchJson(route('panel.estado-local'), ['estado' => 'cerrado', 'confirmar' => '1'])
            ->assertOk()
            ->assertJsonPath('abierto', false)
            ->assertJsonPath('estado', 'cerrado');

        $this->assertSame('cerrado', $this->restaurante->fresh()->estado_local);
    }

    public function test_opening_needs_no_confirmation_and_repeating_is_harmless(): void
    {
        $this->restaurante->update(['estado_local' => 'cerrado']);
        $cajero = User::factory()->cajero($this->restaurante)->create();

        $this->actingAs($cajero)
            ->patchJson(route('panel.estado-local'), ['estado' => 'abierto'])
            ->assertOk()
            ->assertJsonPath('abierto', true);

        $this->patchJson(route('panel.estado-local'), ['estado' => 'abierto'])->assertOk();

        $this->assertSame('abierto', $this->restaurante->fresh()->estado_local);
        $this->assertSame(1, ActividadLog::where('accion', 'cambio_estado_local')->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesSinInterruptor(): array
    {
        return ['mesero' => ['mesero'], 'repartidor' => ['repartidor']];
    }

    #[DataProvider('rolesSinInterruptor')]
    public function test_other_roles_cannot_change_the_state_and_see_it_read_only(string $rol): void
    {
        $usuario = User::factory()->personal($rol, $this->restaurante)->create();

        $this->actingAs($usuario)
            ->patchJson(route('panel.estado-local'), ['estado' => 'cerrado', 'confirmar' => '1'])
            ->assertForbidden();

        $this->assertSame('abierto', $this->restaurante->fresh()->estado_local);

        $this->get(route('panel.inicio'))->assertOk()->assertDontSee('data-estado-local', false);
    }

    public function test_kitchen_cannot_change_the_state_and_sees_it_read_only_on_its_orders(): void
    {
        $cocina = User::factory()->personal('cocina', $this->restaurante)->create();

        $this->actingAs($cocina)
            ->patchJson(route('panel.estado-local'), ['estado' => 'cerrado', 'confirmar' => '1'])
            ->assertForbidden();

        $this->assertSame('abierto', $this->restaurante->fresh()->estado_local);

        $this->get(route('panel.pedidos.index'))->assertOk()->assertDontSee('data-estado-local', false);
    }

    public function test_superadmin_is_asked_to_pick_a_restaurant(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->patchJson(route('panel.estado-local'), ['estado' => 'cerrado', 'confirmar' => '1'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Selecciona un restaurante.');
    }

    public function test_only_valid_states_are_accepted_and_it_only_touches_their_own_restaurant(): void
    {
        $ajeno = Restaurante::factory()->create(['estado_local' => 'abierto']);
        $admin = User::factory()->admin($this->restaurante)->create();

        $this->actingAs($admin)
            ->patchJson(route('panel.estado-local'), ['estado' => 'quemado'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estado');

        $this->patchJson(route('panel.estado-local'), ['estado' => 'cerrado', 'confirmar' => '1', 'restaurante_id' => $ajeno->id])->assertOk();

        $this->assertSame('cerrado', $this->restaurante->fresh()->estado_local);
        $this->assertSame('abierto', $ajeno->fresh()->estado_local);
    }

    public function test_the_sidebar_switch_is_live_for_admin_and_cashier(): void
    {
        $this->actingAs(User::factory()->admin($this->restaurante)->create())
            ->get(route('panel.inicio'))
            ->assertOk()
            ->assertSee('data-estado-local', false)
            ->assertSee(route('panel.estado-local'))
            ->assertSee('¿Cerrar el local?')
            ->assertDontSee('disponible en Configuración');
    }
}
