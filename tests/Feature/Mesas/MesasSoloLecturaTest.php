<?php

namespace Tests\Feature\Mesas;

use App\Http\Middleware\EnsureLocalOperativo;
use App\Models\Area;
use App\Models\Mesa;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Local fuera de operación: el mapa se puede ver, pero nada de Mesas se escribe.
 */
class MesasSoloLecturaTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private Area $salon;

    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'America/Lima'));

        $this->restaurante = Restaurante::factory()->conModulos('mesas')->create();
        $this->salon = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Salón']);
        $this->mesa = Mesa::factory()->for($this->salon)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'Ventana 1', 'activa' => false]);
        $this->actingAs(User::factory()->admin($this->restaurante)->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fueraDeOperacion(): void
    {
        Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'vencida', 'vence_el' => '2026-10-01']);
        $this->assertFalse($this->restaurante->fresh()->operativo());
    }

    public function test_out_of_operation_blocks_every_write_of_the_module(): void
    {
        $this->fueraDeOperacion();

        $escrituras = [
            ['post', route('panel.mesas.store'), ['codigo' => 'Z1', 'capacidad' => 2, 'area_id' => $this->salon->id]],
            ['post', route('panel.mesas.varias'), ['area_id' => $this->salon->id, 'desde' => 1, 'hasta' => 3, 'capacidad' => 2]],
            ['put', route('panel.mesas.update', $this->mesa), ['codigo' => 'Z2', 'capacidad' => 2, 'area_id' => $this->salon->id]],
            ['patch', route('panel.mesas.estado', $this->mesa), ['estado' => 'fuera_servicio']],
            ['patch', route('panel.mesas.activa', $this->mesa), ['activa' => 1]],
            ['patch', route('panel.mesas.mover', $this->mesa), ['direccion' => 'bajar']],
            ['post', route('panel.mesas.areas.store'), ['nombre' => 'Terraza']],
            ['put', route('panel.mesas.areas.update', $this->salon), ['nombre' => 'Otro']],
            ['patch', route('panel.mesas.areas.activa', $this->salon), ['activa' => 0]],
            ['patch', route('panel.mesas.areas.mover', $this->salon), ['direccion' => 'bajar']],
            ['delete', route('panel.mesas.areas.destroy', $this->salon), []],
        ];

        foreach ($escrituras as [$metodo, $url, $datos]) {
            $this->{$metodo.'Json'}($url, $datos)
                ->assertForbidden()
                ->assertJsonPath('message', EnsureLocalOperativo::MENSAJE);
        }

        $this->assertSame(1, Mesa::count());
        $this->assertDatabaseHas('mesas', ['id' => $this->mesa->id, 'codigo' => 'Ventana 1', 'estado' => 'disponible', 'activa' => false]);
        $this->assertDatabaseHas('areas', ['id' => $this->salon->id, 'nombre' => 'Salón', 'activa' => true]);
    }

    public function test_out_of_operation_can_still_see_the_map(): void
    {
        $this->fueraDeOperacion();

        $this->get(route('panel.mesas.index', ['editar' => 1]))->assertOk()->assertSee('Ventana 1');
    }

    public function test_without_javascript_it_goes_back_with_the_message(): void
    {
        $this->fueraDeOperacion();

        $this->from(route('panel.mesas.index'))
            ->post(route('panel.mesas.areas.store'), ['nombre' => 'Terraza'])
            ->assertRedirect(route('panel.mesas.index'))
            ->assertSessionHas('error', EnsureLocalOperativo::MENSAJE);
    }
}
