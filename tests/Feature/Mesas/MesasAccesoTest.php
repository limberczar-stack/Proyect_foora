<?php

namespace Tests\Feature\Mesas;

use App\Models\Area;
use App\Models\Mesa;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Quién entra a Mesas, qué ve cada rol y que nada cruce entre locales ni salga con el módulo apagado.
 */
class MesasAccesoTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    private Area $salon;

    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->conModulos('mesas')->create();
        $this->admin = User::factory()->admin($this->restaurante)->create();
        $this->salon = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Salón principal']);
        $this->mesa = Mesa::factory()->for($this->salon)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'Ventana 1', 'capacidad' => 4]);
    }

    private function usuario(string $rol, ?Restaurante $restaurante = null): User
    {
        return User::factory()->personal($rol, $restaurante ?? $this->restaurante)->create();
    }

    /**
     * Cada escritura del módulo con datos válidos, sobre la mesa y el área dadas.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function escrituras(Mesa $mesa, Area $area): array
    {
        return [
            'crear mesa' => ['post', route('panel.mesas.store'), ['codigo' => 'Z9', 'capacidad' => 2, 'area_id' => $area->id]],
            'agregar varias' => ['post', route('panel.mesas.varias'), ['area_id' => $area->id, 'desde' => 20, 'hasta' => 21, 'capacidad' => 2]],
            'editar mesa' => ['put', route('panel.mesas.update', $mesa), ['codigo' => 'Z8', 'capacidad' => 2, 'area_id' => $area->id]],
            'estado' => ['patch', route('panel.mesas.estado', $mesa), ['estado' => 'fuera_servicio']],
            'activa' => ['patch', route('panel.mesas.activa', $mesa), ['activa' => 0]],
            'mover mesa' => ['patch', route('panel.mesas.mover', $mesa), ['direccion' => 'bajar']],
            'crear área' => ['post', route('panel.mesas.areas.store'), ['nombre' => 'Patio nuevo']],
            'editar área' => ['put', route('panel.mesas.areas.update', $area), ['nombre' => 'Renombrada']],
            'activar área' => ['patch', route('panel.mesas.areas.activa', $area), ['activa' => 0]],
            'mover área' => ['patch', route('panel.mesas.areas.mover', $area), ['direccion' => 'bajar']],
            'eliminar área' => ['delete', route('panel.mesas.areas.destroy', $area), []],
        ];
    }

    private function enviar(string $metodo, string $url, array $datos): TestResponse
    {
        return $this->{$metodo.'Json'}($url, $datos);
    }

    private function assertNadaCambio(): void
    {
        $this->assertSame(1, Mesa::withoutGlobalScopes()->count());
        $this->assertSame(1, Area::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('mesas', ['id' => $this->mesa->id, 'codigo' => 'Ventana 1', 'estado' => 'disponible', 'activa' => true]);
        $this->assertDatabaseHas('areas', ['id' => $this->salon->id, 'nombre' => 'Salón principal', 'activa' => true]);
    }

    public function test_admin_sees_the_map_with_management_actions(): void
    {
        $this->actingAs($this->admin)->get(route('panel.mesas.index', ['editar' => 1]))
            ->assertOk()
            ->assertSee('Salón principal')
            ->assertSee('Ventana 1')
            ->assertSee('Agregar varias')
            ->assertSee('data-mesa-editar', false)
            ->assertSee('id="modal-mesa"', false);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesQueSoloVen(): array
    {
        return ['cajero' => ['cajero'], 'mesero' => ['mesero']];
    }

    #[DataProvider('rolesQueSoloVen')]
    public function test_cashier_and_waiter_see_the_map_without_management(string $rol): void
    {
        $this->actingAs($this->usuario($rol))->get(route('panel.mesas.index'))
            ->assertOk()
            ->assertSee('Ventana 1')
            ->assertSee('Disponible')
            ->assertDontSee('Agregar varias')
            ->assertDontSee('data-mesa-editar', false)
            ->assertDontSee('id="modal-mesa"', false);
    }

    #[DataProvider('rolesQueSoloVen')]
    public function test_cashier_and_waiter_cannot_write(string $rol): void
    {
        $this->actingAs($this->usuario($rol));

        foreach ($this->escrituras($this->mesa, $this->salon) as $accion => [$metodo, $url, $datos]) {
            $this->assertSame(403, $this->enviar($metodo, $url, $datos)->getStatusCode(), "{$rol} pudo: {$accion}");
        }

        $this->assertNadaCambio();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesSinMesas(): array
    {
        return ['cocina' => ['cocina'], 'repartidor' => ['repartidor']];
    }

    #[DataProvider('rolesSinMesas')]
    public function test_kitchen_and_delivery_get_403(string $rol): void
    {
        $this->actingAs($this->usuario($rol));

        $this->get(route('panel.mesas.index'))->assertForbidden();

        foreach ($this->escrituras($this->mesa, $this->salon) as $accion => [$metodo, $url, $datos]) {
            $this->assertSame(403, $this->enviar($metodo, $url, $datos)->getStatusCode(), "{$rol} pudo: {$accion}");
        }

        $this->assertNadaCambio();
    }

    public function test_tables_and_areas_of_another_restaurant_are_404(): void
    {
        $otro = Restaurante::factory()->conModulos('mesas')->create();
        $areaAjena = Area::factory()->create(['restaurante_id' => $otro->id, 'nombre' => 'Terraza ajena']);
        $mesaAjena = Mesa::factory()->for($areaAjena)->create(['restaurante_id' => $otro->id, 'codigo' => 'Ajena 1']);

        $this->actingAs($this->admin);

        foreach ($this->escrituras($mesaAjena, $areaAjena) as $accion => [$metodo, $url, $datos]) {
            if (in_array($accion, ['crear mesa', 'agregar varias', 'crear área'], true)) {
                continue;
            }

            $this->assertSame(404, $this->enviar($metodo, $url, $datos)->getStatusCode(), "Se pudo: {$accion} en otro local");
        }

        $this->get(route('panel.mesas.index'))->assertOk()->assertDontSee('Terraza ajena')->assertDontSee('Ajena 1');
        $this->assertDatabaseHas('mesas', ['id' => $mesaAjena->id, 'codigo' => 'Ajena 1', 'estado' => 'disponible', 'activa' => true]);
        $this->assertDatabaseHas('areas', ['id' => $areaAjena->id, 'nombre' => 'Terraza ajena', 'activa' => true]);
    }

    public function test_creating_in_an_area_of_another_restaurant_is_rejected(): void
    {
        $areaAjena = Area::factory()->create(['restaurante_id' => Restaurante::factory()->create()->id]);

        $this->actingAs($this->admin);

        $this->postJson(route('panel.mesas.store'), ['codigo' => 'Z1', 'capacidad' => 2, 'area_id' => $areaAjena->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['area_id' => 'Elige un área de tu local.']);
        $this->postJson(route('panel.mesas.varias'), ['area_id' => $areaAjena->id, 'desde' => 1, 'hasta' => 2, 'capacidad' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('area_id');
        $this->putJson(route('panel.mesas.update', $this->mesa), ['codigo' => 'Ventana 1', 'capacidad' => 4, 'area_id' => $areaAjena->id])
            ->assertUnprocessable()->assertJsonValidationErrors('area_id');

        $this->assertSame(0, Mesa::withoutGlobalScopes()->where('area_id', $areaAjena->id)->count());
    }

    public function test_module_off_in_the_restaurant_answers_404_everywhere(): void
    {
        $this->restaurante->forceFill(['modulos' => null])->save();

        foreach ([$this->admin, $this->usuario('cajero'), $this->usuario('mesero'), $this->usuario('cocina')] as $usuario) {
            $this->actingAs($usuario)->get(route('panel.mesas.index'))->assertNotFound();
        }

        $this->actingAs($this->admin);

        foreach ($this->escrituras($this->mesa, $this->salon) as $accion => [$metodo, $url, $datos]) {
            $this->assertSame(404, $this->enviar($metodo, $url, $datos)->getStatusCode(), "Con el módulo apagado se pudo: {$accion}");
        }

        $this->assertNadaCambio();
    }

    public function test_module_off_in_foora_answers_404_even_if_the_restaurant_has_it(): void
    {
        config(['foora.modulos.mesas' => false]);

        $this->actingAs($this->admin)->get(route('panel.mesas.index'))->assertNotFound();
        $this->postJson(route('panel.mesas.store'), ['codigo' => 'Z1', 'capacidad' => 2, 'area_id' => $this->salon->id])->assertNotFound();
    }

    public function test_superadmin_does_not_enter_a_restaurant_map(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())->get(route('panel.mesas.index'))->assertNotFound();
    }

    public function test_menu_offers_mesas_only_to_roles_that_see_it_and_only_with_the_module_on(): void
    {
        foreach (['admin' => $this->admin, 'cajero' => $this->usuario('cajero'), 'mesero' => $this->usuario('mesero')] as $rol => $usuario) {
            $this->actingAs($usuario)->get(route('panel.mi-cuenta'))->assertSee(route('panel.mesas.index'), false);
        }

        $this->actingAs($this->usuario('cocina'))->get(route('panel.mi-cuenta'))->assertDontSee(route('panel.mesas.index'), false);

        $this->restaurante->forceFill(['modulos' => null])->save();
        $this->actingAs($this->admin->fresh())->get(route('panel.mi-cuenta'))->assertDontSee(route('panel.mesas.index'), false);
    }

    public function test_waiter_home_goes_to_the_map_when_the_module_is_on(): void
    {
        $mesero = $this->usuario('mesero');

        $this->actingAs($mesero)->get(route('panel.inicio'))->assertRedirect(route('panel.mesas.index'));

        $this->restaurante->forceFill(['modulos' => null])->save();

        $this->actingAs($mesero->fresh())->get(route('panel.inicio'))
            ->assertOk()
            ->assertSee('Tu cuenta aún no tiene módulos habilitados');
    }

    public function test_other_roles_keep_their_home_with_the_module_on(): void
    {
        $this->actingAs($this->admin)->get(route('panel.inicio'))->assertOk();
        $this->actingAs($this->usuario('cajero'))->get(route('panel.inicio'))->assertOk();
        $this->actingAs($this->usuario('cocina'))->get(route('panel.inicio'))->assertRedirect(route('panel.pedidos.index'));
        $this->actingAs($this->usuario('repartidor'))->get(route('panel.inicio'))->assertOk()->assertSee('Tu cuenta aún no tiene módulos habilitados');
    }

    public function test_a_deactivated_area_is_hidden_from_cashier_and_waiter_and_dimmed_for_the_admin(): void
    {
        $terraza = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Terraza', 'activa' => false, 'orden' => 1]);
        Mesa::factory()->for($terraza)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'Patio 1']);

        foreach (['cajero', 'mesero'] as $rol) {
            $this->actingAs($this->usuario($rol))->get(route('panel.mesas.index'))
                ->assertOk()
                ->assertSee('Ventana 1')
                ->assertDontSee('Terraza')
                ->assertDontSee('Patio 1');
        }

        $this->actingAs($this->admin)->get(route('panel.mesas.index', ['editar' => 1]))
            ->assertOk()
            ->assertSee('Terraza (desactivada)')
            ->assertSee('Área desactivada: cajeros y meseros no la ven.')
            ->assertSee('Editar mesa Patio 1')
            ->assertSee('opacity-55');
    }

    public function test_a_deactivated_table_is_only_shown_to_the_admin(): void
    {
        Mesa::factory()->for($this->salon)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'Ventana 2', 'activa' => false]);

        $this->actingAs($this->usuario('mesero'))->get(route('panel.mesas.index'))->assertSee('Ventana 1')->assertDontSee('Ventana 2');
        $this->actingAs($this->admin)->get(route('panel.mesas.index', ['editar' => 1]))->assertSee('Editar mesa Ventana 2')->assertSee('Desactivada');
    }

    public function test_the_map_shows_the_out_of_service_state(): void
    {
        $this->mesa->forceFill(['estado' => 'fuera_servicio'])->save();

        $this->actingAs($this->usuario('mesero'))->get(route('panel.mesas.index'))
            ->assertOk()
            ->assertSee('Fuera de servicio')
            ->assertSee('data-mesa-estado="fuera_servicio"', false);
    }

    public function test_the_table_component_is_ready_for_occupied_and_reserved(): void
    {
        $ocupada = $this->blade('<x-mesa codigo="M4" :capacidad="4" estado="ocupada">45 min · S/ 120.00</x-mesa>');
        $ocupada->assertSee('Ocupada')->assertSee('45 min · S/ 120.00')->assertSee('bg-warning-dot', false);

        $reservada = $this->blade('<x-mesa codigo="M5" :capacidad="1" estado="reservada" />');
        $reservada->assertSee('Reservada')->assertSee('1 persona')->assertSee('bg-brand-700', false);
    }
}
