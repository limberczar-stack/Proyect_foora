<?php

namespace Tests\Feature\Mesas;

use App\Models\ActividadLog;
use App\Models\Area;
use App\Models\Mesa;
use App\Models\Restaurante;
use App\Models\User;
use App\Services\GestionarMesas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GestionMesasTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    private Area $salon;

    private Area $terraza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->conModulos('mesas')->create();
        $this->admin = User::factory()->admin($this->restaurante)->create();
        $this->salon = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Salón principal', 'orden' => 0]);
        $this->terraza = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Terraza', 'orden' => 1]);
        $this->actingAs($this->admin);
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    private function mesa(array $cambios = []): Mesa
    {
        return Mesa::factory()->for($cambios['area'] ?? $this->salon)->create([
            'restaurante_id' => $this->restaurante->id,
            'codigo' => 'Ventana 1',
            'capacidad' => 4,
            ...array_diff_key($cambios, ['area' => true]),
        ]);
    }

    public function test_admin_creates_a_table_at_the_end_of_its_area(): void
    {
        $this->mesa(['codigo' => 'Ventana 1', 'orden' => 0]);

        $this->post(route('panel.mesas.store'), ['codigo' => '  Barra   alta 2 ', 'capacidad' => 6, 'area_id' => $this->salon->id])
            ->assertRedirect(route('panel.mesas.index', ['editar' => 1, 'area' => $this->salon->id]))
            ->assertSessionHas('status', 'Se creó la mesa Barra alta 2.');

        $mesa = Mesa::where('codigo', 'Barra alta 2')->sole();
        $this->assertSame([$this->restaurante->id, $this->salon->id, 6, 1, 'disponible', true], [$mesa->restaurante_id, $mesa->area_id, $mesa->capacidad, $mesa->orden, $mesa->estado, $mesa->activa]);

        $log = ActividadLog::where('accion', 'creo_mesa')->sole();
        $this->assertSame('mesas', $log->modulo);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('Barra alta 2', $log->datos_despues['codigo']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function datosInvalidos(): array
    {
        return [
            'sin código' => [['codigo' => ''], 'codigo'],
            'código largo' => [['codigo' => str_repeat('A', 21)], 'codigo'],
            'código con símbolos' => [['codigo' => 'M<1>'], 'codigo'],
            'capacidad cero' => [['capacidad' => 0], 'capacidad'],
            'capacidad 31' => [['capacidad' => 31], 'capacidad'],
            'capacidad decimal' => [['capacidad' => 2.5], 'capacidad'],
            'sin área' => [['area_id' => null], 'area_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    #[DataProvider('datosInvalidos')]
    public function test_invalid_table_data_is_rejected(array $cambios, string $campo): void
    {
        $this->postJson(route('panel.mesas.store'), [...['codigo' => 'Ventana 9', 'capacidad' => 4, 'area_id' => $this->salon->id], ...$cambios])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);

        $this->assertSame(0, Mesa::count());
    }

    public function test_capacity_limits_one_and_thirty_are_valid(): void
    {
        $this->postJson(route('panel.mesas.store'), ['codigo' => 'Uno', 'capacidad' => 1, 'area_id' => $this->salon->id])->assertOk();
        $this->postJson(route('panel.mesas.store'), ['codigo' => 'Treinta', 'capacidad' => 30, 'area_id' => $this->salon->id])->assertOk()
            ->assertJsonPath('mensaje', 'Se creó la mesa Treinta.');
    }

    public function test_the_code_is_unique_among_active_tables_ignoring_case(): void
    {
        $this->mesa(['codigo' => 'Ventana 1']);

        $this->postJson(route('panel.mesas.store'), ['codigo' => 'VENTANA 1', 'capacidad' => 2, 'area_id' => $this->terraza->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['codigo' => 'Ya tienes una mesa activa «Ventana 1». Usa otro nombre o número.']);

        $this->assertSame(1, Mesa::count());
    }

    public function test_the_code_of_a_deactivated_table_can_be_reused(): void
    {
        $vieja = $this->mesa(['codigo' => 'Ventana 1', 'activa' => false]);

        $this->postJson(route('panel.mesas.store'), ['codigo' => 'Ventana 1', 'capacidad' => 2, 'area_id' => $this->salon->id])->assertOk();

        $this->assertSame(2, Mesa::where('codigo', 'Ventana 1')->count());
        $this->assertFalse($vieja->fresh()->activa);
    }

    public function test_reactivating_a_table_whose_code_is_taken_gives_a_clear_validation_error(): void
    {
        $vieja = $this->mesa(['codigo' => 'Ventana 1', 'activa' => false]);
        $this->mesa(['codigo' => 'Ventana 1', 'area' => $this->terraza]);

        $this->patchJson(route('panel.mesas.activa', $vieja), ['activa' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['codigo' => 'Ya tienes una mesa activa «Ventana 1». Usa otro nombre o número.']);

        $this->from(route('panel.mesas.index', ['editar' => 1]))
            ->patch(route('panel.mesas.activa', $vieja), ['activa' => 1])
            ->assertRedirect(route('panel.mesas.index', ['editar' => 1]))
            ->assertSessionHasErrors('codigo');

        $this->assertFalse($vieja->fresh()->activa);
        $this->from(route('panel.mesas.index', ['editar' => 1]))->followingRedirects()
            ->patch(route('panel.mesas.activa', $vieja), ['activa' => 1])
            ->assertOk()
            ->assertSee('No se pudo completar')
            ->assertSee('Ya tienes una mesa activa «Ventana 1».');
    }

    /**
     * Otra petición ocupa el código entre la validación y el guardado: el índice uq_mesa_activa lo rechaza
     * y la respuesta sigue siendo un error de validación, nunca un 500.
     */
    public function test_reactivation_that_loses_the_race_to_the_unique_index_is_a_validation_error(): void
    {
        $vieja = $this->mesa(['codigo' => 'Ventana 1', 'activa' => false]);
        $this->colarMesaActivaAlGuardar('Ventana 1');

        $this->patchJson(route('panel.mesas.activa', $vieja), ['activa' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['codigo' => 'Ese nombre o número ya lo usa otra mesa activa. Usa otro.']);

        $this->assertFalse($vieja->fresh()->activa);
        $this->assertSame(0, ActividadLog::where('accion', 'activo_mesa')->count());
    }

    public function test_creating_a_table_that_loses_the_race_to_the_unique_index_is_a_validation_error(): void
    {
        $this->colarMesaActivaAlGuardar('Ventana 1', alCrear: true);

        $this->postJson(route('panel.mesas.store'), ['codigo' => 'Ventana 1', 'capacidad' => 2, 'area_id' => $this->salon->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('codigo');

        $this->assertSame(0, Mesa::count());
    }

    /**
     * Justo antes de que GestionarMesas guarde, otra "petición" inserta una mesa activa con ese código.
     */
    private function colarMesaActivaAlGuardar(string $codigo, bool $alCrear = false): void
    {
        $restauranteId = $this->restaurante->id;
        $areaId = $this->terraza->id;
        $colada = false;

        Mesa::{$alCrear ? 'creating' : 'updating'}(function () use (&$colada, $codigo, $restauranteId, $areaId): void {
            if ($colada) {
                return;
            }

            $colada = true;
            DB::table('mesas')->insert([
                'restaurante_id' => $restauranteId,
                'area_id' => $areaId,
                'codigo' => $codigo,
                'capacidad' => 2,
                'orden' => 0,
                'estado' => 'disponible',
                'activa' => true,
            ]);
        });
    }

    public function test_editing_keeps_its_own_code_and_moving_area_puts_it_last(): void
    {
        $this->mesa(['codigo' => 'Patio 1', 'area' => $this->terraza, 'orden' => 0]);
        $mesa = $this->mesa(['codigo' => 'Ventana 1']);

        $this->putJson(route('panel.mesas.update', $mesa), ['codigo' => 'Ventana 1', 'capacidad' => 8, 'area_id' => $this->terraza->id])
            ->assertOk()
            ->assertJsonPath('mensaje', 'Se guardaron los cambios de la mesa Ventana 1.');

        $mesa->refresh();
        $this->assertSame([8, $this->terraza->id, 1], [$mesa->capacidad, $mesa->area_id, $mesa->orden]);

        $log = ActividadLog::where('accion', 'edito_mesa')->sole();
        $this->assertSame(['capacidad' => 4, 'area_id' => $this->salon->id], $log->datos_antes);
    }

    public function test_editing_to_a_code_used_by_another_active_table_is_rejected(): void
    {
        $this->mesa(['codigo' => 'Ventana 1']);
        $otra = $this->mesa(['codigo' => 'Ventana 2']);

        $this->putJson(route('panel.mesas.update', $otra), ['codigo' => 'ventana 1', 'capacidad' => 4, 'area_id' => $this->salon->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('codigo');

        $this->assertSame('Ventana 2', $otra->fresh()->codigo);
    }

    public function test_out_of_service_is_set_and_cleared_by_hand(): void
    {
        $mesa = $this->mesa();

        $this->patchJson(route('panel.mesas.estado', $mesa), ['estado' => 'fuera_servicio'])
            ->assertOk()->assertJsonPath('mensaje', 'La mesa Ventana 1 quedó fuera de servicio.');
        $this->assertSame('fuera_servicio', $mesa->fresh()->estado);

        $this->patchJson(route('panel.mesas.estado', $mesa), ['estado' => 'disponible'])
            ->assertOk()->assertJsonPath('mensaje', 'La mesa Ventana 1 está disponible.');
        $this->assertSame('disponible', $mesa->fresh()->estado);

        $this->assertSame(2, ActividadLog::where('accion', 'cambio_estado_mesa')->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function estadosNoManuales(): array
    {
        return ['ocupada' => ['ocupada'], 'reservada' => ['reservada'], 'inventado' => ['rota']];
    }

    #[DataProvider('estadosNoManuales')]
    public function test_occupied_or_reserved_cannot_be_set_by_any_request(string $estado): void
    {
        $mesa = $this->mesa();

        $this->patchJson(route('panel.mesas.estado', $mesa), ['estado' => $estado])
            ->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->putJson(route('panel.mesas.update', $mesa), ['codigo' => 'Ventana 1', 'capacidad' => 4, 'area_id' => $this->salon->id, 'estado' => $estado])
            ->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->postJson(route('panel.mesas.store'), ['codigo' => 'Ventana 5', 'capacidad' => 4, 'area_id' => $this->salon->id, 'estado' => $estado])
            ->assertUnprocessable()->assertJsonValidationErrors('estado');

        $this->assertSame('disponible', $mesa->fresh()->estado);
        $this->assertSame(1, Mesa::count());
    }

    public function test_the_service_itself_refuses_non_manual_states(): void
    {
        $mesa = $this->mesa();

        $this->expectException(ValidationException::class);

        try {
            app(GestionarMesas::class)->cambiarEstadoMesa($this->admin, $mesa, 'ocupada');
        } finally {
            $this->assertSame('disponible', $mesa->fresh()->estado);
        }
    }

    public function test_an_occupied_table_cannot_be_changed_by_hand_or_deactivated(): void
    {
        $mesa = $this->mesa(['estado' => 'ocupada']);

        $this->patchJson(route('panel.mesas.estado', $mesa), ['estado' => 'disponible'])
            ->assertUnprocessable()->assertJsonValidationErrors(['estado' => 'La mesa Ventana 1 está ocupada: no puedes cambiarla ahora.']);
        $this->patchJson(route('panel.mesas.activa', $mesa), ['activa' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('estado');

        $this->assertSame(['ocupada', true], [$mesa->fresh()->estado, $mesa->fresh()->activa]);
    }

    public function test_tables_are_deactivated_not_deleted(): void
    {
        $mesa = $this->mesa();

        $this->patchJson(route('panel.mesas.activa', $mesa), ['activa' => 0])
            ->assertOk()
            ->assertJsonPath('mensaje', 'La mesa Ventana 1 quedó desactivada: ya no aparece en el mapa ni cuenta para tu plan.');

        $this->assertDatabaseHas('mesas', ['id' => $mesa->id, 'activa' => false]);
        $this->assertSame(1, ActividadLog::where('accion', 'desactivo_mesa')->count());

        $this->patchJson(route('panel.mesas.activa', $mesa), ['activa' => 1])->assertOk();
        $this->assertTrue($mesa->fresh()->activa);
    }

    public function test_tables_move_up_and_down_inside_their_area(): void
    {
        $a = $this->mesa(['codigo' => 'A', 'orden' => 0]);
        $b = $this->mesa(['codigo' => 'B', 'orden' => 1]);
        $c = $this->mesa(['codigo' => 'C', 'orden' => 2]);
        $otraArea = $this->mesa(['codigo' => 'Z', 'orden' => 0, 'area' => $this->terraza]);

        $this->patch(route('panel.mesas.mover', $c), ['direccion' => 'subir'])->assertRedirect();
        $this->assertSame(['A', 'C', 'B'], Mesa::where('area_id', $this->salon->id)->ordenadas()->pluck('codigo')->all());

        $this->patch(route('panel.mesas.mover', $a), ['direccion' => 'subir'])->assertRedirect();
        $this->assertSame(['A', 'C', 'B'], Mesa::where('area_id', $this->salon->id)->ordenadas()->pluck('codigo')->all());

        $this->patchJson(route('panel.mesas.mover', $a), ['direccion' => 'lado'])->assertUnprocessable();
        $this->assertSame(0, $otraArea->fresh()->orden);
    }

    public function test_add_several_creates_the_range_with_a_prefix(): void
    {
        $this->postJson(route('panel.mesas.varias'), ['area_id' => $this->terraza->id, 'prefijo' => 'T', 'desde' => 1, 'hasta' => 4, 'capacidad' => 2])
            ->assertOk()
            ->assertJsonPath('mensaje', 'Se crearon 4 mesas: de T1 a T4.');

        $mesas = Mesa::where('area_id', $this->terraza->id)->ordenadas()->get();
        $this->assertSame(['T1', 'T2', 'T3', 'T4'], $mesas->pluck('codigo')->all());
        $this->assertSame([0, 1, 2, 3], $mesas->pluck('orden')->all());
        $this->assertSame([2], $mesas->pluck('capacidad')->unique()->values()->all());

        $log = ActividadLog::where('accion', 'agrego_mesas')->sole();
        $this->assertSame(['T1', 'T2', 'T3', 'T4'], $log->datos_despues['codigos']);
    }

    public function test_add_several_without_prefix_uses_plain_numbers(): void
    {
        $this->postJson(route('panel.mesas.varias'), ['area_id' => $this->salon->id, 'desde' => 9, 'hasta' => 11, 'capacidad' => 4])->assertOk();

        $this->assertSame(['9', '10', '11'], Mesa::ordenadas()->pluck('codigo')->all());
    }

    public function test_add_several_creates_nothing_if_any_code_is_taken(): void
    {
        $this->mesa(['codigo' => 'T2', 'area' => $this->terraza]);
        $this->mesa(['codigo' => 't3', 'area' => $this->terraza]);

        $this->postJson(route('panel.mesas.varias'), ['area_id' => $this->terraza->id, 'prefijo' => 'T', 'desde' => 1, 'hasta' => 4, 'capacidad' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['codigo' => 'Ya tienes mesas activas con estos códigos: T2, t3. Cambia el rango o el prefijo.']);

        $this->assertSame(2, Mesa::count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function rangosInvalidos(): array
    {
        return [
            'hasta menor' => [['desde' => 5, 'hasta' => 3], 'hasta'],
            'desde cero' => [['desde' => 0, 'hasta' => 3], 'desde'],
            'más de 50' => [['desde' => 1, 'hasta' => 51], 'hasta'],
            'prefijo que no cabe' => [['prefijo' => str_repeat('P', 18), 'desde' => 1, 'hasta' => 100], 'prefijo'],
            'prefijo con símbolos' => [['prefijo' => '#'], 'prefijo'],
            'capacidad fuera de rango' => [['capacidad' => 40], 'capacidad'],
        ];
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    #[DataProvider('rangosInvalidos')]
    public function test_invalid_ranges_are_rejected(array $cambios, string $campo): void
    {
        $this->postJson(route('panel.mesas.varias'), [...['area_id' => $this->salon->id, 'desde' => 1, 'hasta' => 3, 'capacidad' => 2], ...$cambios])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);

        $this->assertSame(0, Mesa::count());
    }

    public function test_a_prefix_that_fits_exactly_in_the_code_is_accepted(): void
    {
        $prefijo = str_repeat('P', 17);

        $this->postJson(route('panel.mesas.varias'), ['area_id' => $this->salon->id, 'prefijo' => $prefijo, 'desde' => 998, 'hasta' => 999, 'capacidad' => 2])
            ->assertOk();

        $this->assertSame([$prefijo.'998', $prefijo.'999'], Mesa::ordenadas()->pluck('codigo')->all());
        $this->assertSame(20, mb_strlen($prefijo.'999'));
    }

    public function test_without_javascript_a_failed_modal_reopens_with_the_data(): void
    {
        $this->mesa(['codigo' => 'Ventana 1']);

        $this->from(route('panel.mesas.index', ['editar' => 1]))
            ->post(route('panel.mesas.store'), ['_modal' => 'mesa', 'codigo' => 'Ventana 1', 'capacidad' => 3, 'area_id' => $this->salon->id])
            ->assertRedirect(route('panel.mesas.index', ['editar' => 1]))
            ->assertSessionHasErrors('codigo');

        $this->from(route('panel.mesas.index', ['editar' => 1]))->followingRedirects()
            ->post(route('panel.mesas.store'), ['_modal' => 'mesa', 'codigo' => 'Ventana 1', 'capacidad' => 3, 'area_id' => $this->salon->id])
            ->assertOk()
            ->assertSee('data-reabrir="true"', false)
            ->assertSee('Ya tienes una mesa activa «Ventana 1».')
            ->assertDontSee('No se pudo completar');
    }
}
