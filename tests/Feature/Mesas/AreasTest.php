<?php

namespace Tests\Feature\Mesas;

use App\Models\ActividadLog;
use App\Models\Area;
use App\Models\Mesa;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreasTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->conModulos('mesas')->create();
        $this->admin = User::factory()->admin($this->restaurante)->create();
        $this->actingAs($this->admin);
    }

    private function area(string $nombre, int $orden = 0, bool $activa = true): Area
    {
        return Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => $nombre, 'orden' => $orden, 'activa' => $activa]);
    }

    public function test_admin_creates_areas_at_the_end(): void
    {
        $this->area('Salón principal', 0);

        $this->post(route('panel.mesas.areas.store'), ['nombre' => '  Terraza  '])
            ->assertSessionHas('status', 'Se creó el área «Terraza».');

        $terraza = Area::where('nombre', 'Terraza')->sole();
        $this->assertSame([$this->restaurante->id, 1, true], [$terraza->restaurante_id, $terraza->orden, $terraza->activa]);
        $this->assertSame(1, ActividadLog::where('modulo', 'mesas')->where('accion', 'creo_area')->count());
    }

    public function test_the_area_name_is_unique_in_the_restaurant_but_not_across_restaurants(): void
    {
        $this->area('Terraza');
        Area::factory()->create(['restaurante_id' => Restaurante::factory()->create()->id, 'nombre' => 'Patio']);

        $this->postJson(route('panel.mesas.areas.store'), ['nombre' => 'Terraza'])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre' => 'Ya tienes un área con ese nombre.']);
        $this->postJson(route('panel.mesas.areas.store'), ['nombre' => 'Patio'])->assertOk();
        $this->postJson(route('panel.mesas.areas.store'), ['nombre' => ''])->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->postJson(route('panel.mesas.areas.store'), ['nombre' => str_repeat('a', 61)])->assertUnprocessable()->assertJsonValidationErrors('nombre');
    }

    public function test_admin_renames_an_area_and_can_keep_its_own_name(): void
    {
        $area = $this->area('Terraza');

        $this->putJson(route('panel.mesas.areas.update', $area), ['nombre' => 'Terraza'])->assertOk();
        $this->putJson(route('panel.mesas.areas.update', $area), ['nombre' => 'Azotea'])->assertOk()
            ->assertJsonPath('mensaje', 'Se guardaron los cambios de «Azotea».');

        $this->assertSame('Azotea', $area->fresh()->nombre);
        $this->assertSame(['nombre' => 'Terraza'], ActividadLog::where('accion', 'edito_area')->sole()->datos_antes);
    }

    public function test_areas_move_up_and_down(): void
    {
        $this->area('Salón', 0);
        $terraza = $this->area('Terraza', 1);
        $this->area('Patio', 2);

        $this->patch(route('panel.mesas.areas.mover', $terraza), ['direccion' => 'subir'])->assertRedirect();
        $this->assertSame(['Terraza', 'Salón', 'Patio'], Area::ordenadas()->pluck('nombre')->all());

        $this->patch(route('panel.mesas.areas.mover', $terraza), ['direccion' => 'subir']);
        $this->assertSame(['Terraza', 'Salón', 'Patio'], Area::ordenadas()->pluck('nombre')->all());

        $this->patch(route('panel.mesas.areas.mover', $terraza), ['direccion' => 'bajar']);
        $this->patch(route('panel.mesas.areas.mover', $terraza), ['direccion' => 'bajar']);
        $this->assertSame(['Salón', 'Patio', 'Terraza'], Area::ordenadas()->pluck('nombre')->all());
    }

    public function test_areas_are_activated_and_deactivated(): void
    {
        $area = $this->area('Terraza');

        $this->patchJson(route('panel.mesas.areas.activa', $area), ['activa' => 0])->assertOk()
            ->assertJsonPath('mensaje', '«Terraza» quedó desactivada: cajeros y meseros no la ven. Sus mesas activas siguen contando para tu plan.');
        $this->assertFalse($area->fresh()->activa);

        $this->patchJson(route('panel.mesas.areas.activa', $area), ['activa' => 1])->assertOk();
        $this->assertTrue($area->fresh()->activa);
        $this->assertSame(['desactivo_area', 'activo_area'], ActividadLog::orderBy('id')->pluck('accion')->all());
    }

    public function test_an_area_with_tables_cannot_be_deleted_and_the_message_says_how_many(): void
    {
        $area = $this->area('Terraza');
        Mesa::factory()->for($area)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'T1']);
        Mesa::factory()->for($area)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'T2', 'activa' => false]);

        $this->deleteJson(route('panel.mesas.areas.destroy', $area))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['area' => 'No puedes eliminar «Terraza» porque tiene 2 mesas (contando las desactivadas). Muévelas a otra área o desactiva el área.']);

        $this->from(route('panel.mesas.index'))->delete(route('panel.mesas.areas.destroy', $area))
            ->assertRedirect(route('panel.mesas.index'))
            ->assertSessionHasErrors('area');

        $this->assertModelExists($area);
        $this->assertSame(2, Mesa::where('area_id', $area->id)->count());
    }

    public function test_an_area_with_one_table_uses_the_singular(): void
    {
        $area = $this->area('Barra');
        Mesa::factory()->for($area)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'B1']);

        $this->deleteJson(route('panel.mesas.areas.destroy', $area))
            ->assertJsonValidationErrors(['area' => 'No puedes eliminar «Barra» porque tiene 1 mesa (contando las desactivadas). Muévelas a otra área o desactiva el área.']);
    }

    public function test_an_empty_area_is_deleted_and_logged(): void
    {
        $area = $this->area('Terraza');

        $this->delete(route('panel.mesas.areas.destroy', $area))
            ->assertRedirect(route('panel.mesas.index', ['editar' => 1]))
            ->assertSessionHas('status', 'Se eliminó el área «Terraza».');

        $this->assertModelMissing($area);
        $this->assertSame(['nombre' => 'Terraza', 'activa' => true], ActividadLog::where('accion', 'elimino_area')->sole()->datos_antes);
    }

    public function test_the_map_lists_areas_as_tabs_in_their_order(): void
    {
        $terraza = $this->area('Terraza', 1);
        $salon = $this->area('Salón principal', 0);

        $contenido = $this->get(route('panel.mesas.index', ['editar' => 1]))->assertOk()->getContent();
        $posicion = fn (Area $area): int|false => strpos($contenido, 'id="mesas-areas-tab-area-'.$area->id.'"');

        $this->assertNotFalse($posicion($salon));
        $this->assertNotFalse($posicion($terraza));
        $this->assertLessThan($posicion($terraza), $posicion($salon));
    }

    public function test_the_admin_sees_an_empty_state_without_areas(): void
    {
        $this->get(route('panel.mesas.index', ['editar' => 1]))->assertOk()->assertSee('Todavía no tienes áreas.');

        $this->actingAs(User::factory()->personal('mesero', $this->restaurante)->create())
            ->get(route('panel.mesas.index'))->assertOk()->assertSee('El administrador aún no configuró las mesas del local.');
    }
}
