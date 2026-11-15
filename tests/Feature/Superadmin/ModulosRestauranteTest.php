<?php

namespace Tests\Feature\Superadmin;

use App\Models\ActividadLog;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El superadmin enciende o apaga los módulos por local (hoy, Mesas) desde el detalle del restaurante.
 */
class ModulosRestauranteTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private Restaurante $restaurante;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = User::factory()->superadmin()->create();
        $this->restaurante = Restaurante::factory()->create();
    }

    public function test_the_detail_offers_the_module_switch(): void
    {
        $this->actingAs($this->superadmin)->get(route('panel.superadmin.restaurantes.show', $this->restaurante))
            ->assertOk()
            ->assertSee('Mesas y salón')
            ->assertSee('name="modulos[mesas]"', false)
            ->assertSee(route('panel.superadmin.restaurantes.modulos', $this->restaurante), false);
    }

    public function test_superadmin_turns_mesas_on_and_off_and_it_is_logged(): void
    {
        $this->actingAs($this->superadmin);

        $this->putJson(route('panel.superadmin.restaurantes.modulos', $this->restaurante), ['modulos' => ['mesas' => 1]])
            ->assertOk()
            ->assertJsonPath('mensaje', 'Se guardaron los módulos del local.');

        $this->assertTrue($this->restaurante->fresh()->tieneModulo('mesas'));
        $log = ActividadLog::withoutGlobalScopes()->where('accion', 'cambio_modulos')->sole();
        $this->assertSame([$this->restaurante->id, $this->superadmin->id, 'suscripciones'], [$log->restaurante_id, $log->user_id, $log->modulo]);
        $this->assertSame('Módulos del local: encendió Mesas y salón.', $log->descripcion);
        $this->assertSame(['modulos' => []], $log->datos_antes);
        $this->assertSame(['modulos' => ['mesas']], $log->datos_despues);

        $this->putJson(route('panel.superadmin.restaurantes.modulos', $this->restaurante), ['modulos' => ['mesas' => 0]])->assertOk();

        $this->assertFalse($this->restaurante->fresh()->tieneModulo('mesas'));
        $this->assertNull($this->restaurante->fresh()->modulos);
        $this->assertSame('Módulos del local: apagó Mesas y salón.', ActividadLog::withoutGlobalScopes()->where('accion', 'cambio_modulos')->latest('id')->first()->descripcion);
    }

    public function test_saving_without_changes_does_not_log(): void
    {
        $this->actingAs($this->superadmin)
            ->putJson(route('panel.superadmin.restaurantes.modulos', $this->restaurante), ['modulos' => ['mesas' => 0]])
            ->assertOk()
            ->assertJsonPath('mensaje', 'No hubo cambios en los módulos.');

        $this->assertSame(0, ActividadLog::withoutGlobalScopes()->count());
    }

    public function test_unknown_or_not_yet_built_modules_are_ignored(): void
    {
        $this->actingAs($this->superadmin)
            ->putJson(route('panel.superadmin.restaurantes.modulos', $this->restaurante), ['modulos' => ['mesas' => 1, 'reservas' => 1, 'hackeado' => 1]])
            ->assertOk();

        $this->assertSame(['mesas'], $this->restaurante->fresh()->modulos);
    }

    public function test_a_module_turned_off_in_foora_is_kept_as_it_was(): void
    {
        $this->restaurante->forceFill(['modulos' => ['mesas']])->save();
        config(['foora.modulos.mesas' => false]);

        $this->actingAs($this->superadmin)->get(route('panel.superadmin.restaurantes.show', $this->restaurante))
            ->assertOk()->assertDontSee('name="modulos[mesas]"', false);

        $this->putJson(route('panel.superadmin.restaurantes.modulos', $this->restaurante), ['modulos' => []])->assertUnprocessable();
        $this->assertSame(['mesas'], $this->restaurante->fresh()->modulos);
    }

    public function test_the_value_is_required_and_boolean(): void
    {
        $this->actingAs($this->superadmin);

        $this->putJson(route('panel.superadmin.restaurantes.modulos', $this->restaurante), [])->assertUnprocessable()->assertJsonValidationErrors('modulos');
        $this->putJson(route('panel.superadmin.restaurantes.modulos', $this->restaurante), ['modulos' => ['mesas' => 'quizá']])
            ->assertUnprocessable()->assertJsonValidationErrors('modulos.mesas');
    }

    public function test_only_the_superadmin_can_change_modules(): void
    {
        $admin = User::factory()->admin($this->restaurante)->create();

        $this->actingAs($admin)
            ->putJson(route('panel.superadmin.restaurantes.modulos', $this->restaurante), ['modulos' => ['mesas' => 1]])
            ->assertForbidden();

        $this->assertNull($this->restaurante->fresh()->modulos);
    }

    public function test_the_local_cannot_turn_on_its_own_module_through_its_settings(): void
    {
        $admin = User::factory()->admin($this->restaurante)->create();

        $this->actingAs($admin)
            ->putJson(route('panel.configuracion.general.update'), ['nombre' => 'Mi local', 'ciudad' => 'Lima', 'modulos' => ['mesas']]);

        $this->assertNull($this->restaurante->fresh()->modulos);
    }

    public function test_the_local_does_not_see_the_module_change_in_its_activity(): void
    {
        $this->actingAs($this->superadmin)
            ->putJson(route('panel.superadmin.restaurantes.modulos', $this->restaurante), ['modulos' => ['mesas' => 1]]);

        $this->actingAs(User::factory()->admin($this->restaurante)->create());

        $this->assertSame(0, ActividadLog::where('accion', 'cambio_modulos')->count());
    }
}
