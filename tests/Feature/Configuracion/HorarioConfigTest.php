<?php

namespace Tests\Feature\Configuracion;

use App\Models\HorarioAtencion;
use App\Models\Restaurante;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorarioConfigTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->create(['estado_local' => 'abierto']);
        $this->admin = User::factory()->admin($this->restaurante)->create();
    }

    /**
     * @param  array<int, array<string, mixed>>  $cambios
     * @return array{dias: array<int, array<string, mixed>>}
     */
    private function semana(array $cambios = []): array
    {
        $dias = [];

        foreach (range(1, 7) as $dia) {
            $dias[$dia] = $cambios[$dia] ?? ['abre' => '12:00', 'cierra' => '22:00', 'cerrado' => '0'];
        }

        return ['dias' => $dias];
    }

    public function test_admin_saves_the_seven_days_including_a_closed_day_and_one_past_midnight(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.horario.update'), $this->semana([
                1 => ['cerrado' => '1', 'abre' => '', 'cierra' => ''],
                5 => ['abre' => '18:00', 'cierra' => '02:00', 'cerrado' => '0'],
                6 => ['abre' => '9:00', 'cierra' => '23:30:00', 'cerrado' => '0'],
            ]))
            ->assertOk()
            ->assertJsonPath('mensaje', 'Se guardó el horario de atención.');

        $horarios = HorarioAtencion::where('restaurante_id', $this->restaurante->id)->get()->keyBy('dia_semana');

        $this->assertCount(7, $horarios);
        $this->assertTrue($horarios[1]->cerrado);
        $this->assertNull($horarios[1]->abre);
        $this->assertTrue($horarios[5]->cruzaMedianoche());
        $this->assertSame('09:00 a 23:30', $horarios[6]->rangoTexto());

        // Guardar de nuevo actualiza, no duplica.
        $this->putJson(route('panel.configuracion.horario.update'), $this->semana())->assertOk();
        $this->assertSame(7, HorarioAtencion::where('restaurante_id', $this->restaurante->id)->count());
    }

    public function test_saved_hours_drive_abierto_ahora_across_midnight(): void
    {
        // Viernes 6 de noviembre de 2026, de 18:00 a 02:00.
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.horario.update'), $this->semana([
                5 => ['abre' => '18:00', 'cierra' => '02:00', 'cerrado' => '0'],
                6 => ['cerrado' => '1'],
            ]))
            ->assertOk();

        $restaurante = $this->restaurante->fresh()->load('horariosAtencion');

        $this->assertTrue($restaurante->abiertoAhora(Carbon::parse('2026-11-06 23:00', 'America/Lima')));
        $this->assertTrue($restaurante->abiertoAhora(Carbon::parse('2026-11-07 01:30', 'America/Lima')));
        $this->assertFalse($restaurante->abiertoAhora(Carbon::parse('2026-11-07 02:00', 'America/Lima')));
        $this->assertFalse($restaurante->abiertoAhora(Carbon::parse('2026-11-07 20:00', 'America/Lima')));
        $this->assertFalse($restaurante->abiertoAhora(Carbon::parse('2026-11-06 17:59', 'America/Lima')));
    }

    public function test_an_open_day_needs_both_hours_in_a_valid_format(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.horario.update'), $this->semana([
                2 => ['abre' => '', 'cierra' => '22:00', 'cerrado' => '0'],
                3 => ['abre' => '12:00', 'cierra' => '', 'cerrado' => '0'],
                4 => ['abre' => '25:00', 'cierra' => '22:00', 'cerrado' => '0'],
                5 => ['abre' => 'mediodía', 'cierra' => '22:00', 'cerrado' => '0'],
                6 => ['abre' => '20:00', 'cierra' => '20:00', 'cerrado' => '0'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dias.2.abre', 'dias.3.cierra', 'dias.4.abre', 'dias.5.abre', 'dias.6.cierra'])
            ->assertJsonValidationErrors([
                'dias.2.abre' => 'Martes: indica la hora de apertura o márcalo como cerrado.',
                'dias.6.cierra' => 'Sábado: la apertura y el cierre no pueden ser la misma hora.',
            ]);

        $this->assertSame(0, HorarioAtencion::count());
    }

    public function test_a_closed_day_ignores_whatever_hours_were_sent(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.horario.update'), $this->semana([
                7 => ['cerrado' => '1', 'abre' => 'basura', 'cierra' => '99:99'],
            ]))
            ->assertOk();

        $domingo = HorarioAtencion::where('restaurante_id', $this->restaurante->id)->where('dia_semana', 7)->sole();
        $this->assertTrue($domingo->cerrado);
        $this->assertNull($domingo->abre);
        $this->assertNull($domingo->cierra);
    }

    public function test_all_seven_days_are_required_and_nothing_else(): void
    {
        $semana = $this->semana();
        unset($semana['dias'][4]);

        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.horario.update'), $semana)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dias.4');

        $semana = $this->semana();
        $semana['dias'][8] = ['abre' => '10:00', 'cierra' => '12:00'];

        $this->putJson(route('panel.configuracion.horario.update'), $semana)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dias');
    }

    public function test_the_page_offers_copy_to_all_days(): void
    {
        $this->actingAs($this->admin)
            ->get(route('panel.configuracion.horario'))
            ->assertOk()
            ->assertSee('Copiar a todos los días')
            ->assertSee('Domingo');
    }
}
