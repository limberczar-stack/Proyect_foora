<?php

namespace Tests\Feature\Mesas;

use App\Models\Area;
use App\Models\Cuenta;
use App\Models\Mesa;
use App\Models\Restaurante;
use App\Models\User;
use App\Services\GestionarCuentas;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lo que se ve: el plano para atender, su actualización en vivo, el detalle de una cuenta,
 * la tarjeta del inicio y el aviso al desactivar a alguien con cuentas abiertas.
 */
class PlanoMesasTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    private User $cajero;

    private User $mesero;

    private Area $salon;

    /** @var array<string, Mesa> */
    private array $mesas = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'America/Lima'));

        $this->restaurante = Restaurante::factory()->conModulos('mesas')->create();
        $this->admin = User::factory()->admin($this->restaurante)->create(['name' => 'Rosa Quispe']);
        $this->cajero = User::factory()->cajero($this->restaurante)->create(['name' => 'Miguel Torres']);
        $this->mesero = User::factory()->personal('mesero', $this->restaurante)->create(['name' => 'Luis Chávez']);
        $this->salon = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Salón principal']);

        foreach (['Ventana 1', 'Ventana 2', 'Ventana 3', 'Ventana 4'] as $orden => $codigo) {
            $this->mesas[$codigo] = Mesa::factory()->for($this->salon)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => $codigo, 'capacidad' => 4, 'orden' => $orden]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<string>  $codigos
     */
    private function abrir(User $quien, array $codigos, int $comensales = 2): Cuenta
    {
        return app(GestionarCuentas::class)->abrir($quien, array_map(fn (string $codigo): int => $this->mesas[$codigo]->id, $codigos), $comensales);
    }

    private function pasan(int $minutos): void
    {
        Carbon::setTestNow(Carbon::now()->addMinutes($minutos));
    }

    public function test_the_map_shows_free_occupied_and_out_of_service_tables(): void
    {
        $cuenta = $this->abrir($this->mesero, ['Ventana 1', 'Ventana 2'], 6);
        $this->mesas['Ventana 4']->forceFill(['estado' => 'fuera_servicio'])->save();
        $this->pasan(50);

        $respuesta = $this->actingAs($this->cajero)->get(route('panel.mesas.index'))->assertOk();

        $respuesta
            ->assertSee('50 min · 6 comensales')
            ->assertSee('Luis')
            ->assertSee('data-mesa-grupo>Ventana 1 + Ventana 2<', false)
            ->assertSee('href="'.route('panel.mesas.cuentas.show', $cuenta).'"', false)
            ->assertSee('Mesa Ventana 3, disponible. Abrir cuenta')
            ->assertSee('data-mesa-id="'.$this->mesas['Ventana 3']->id.'"', false)
            ->assertSee('Fuera de servicio')
            ->assertSee('data-url="'.route('panel.mesas.plano').'"', false)
            ->assertDontSee('Editar salón')
            ->assertDontSee('Más de 6 h abierta');

        // Solo la mesa libre abre el formulario; la ocupada y la fuera de servicio no.
        $this->assertSame(1, substr_count($respuesta->getContent(), 'data-mesa-id="'));
        $this->assertSame(2, substr_count($respuesta->getContent(), 'data-cuenta-id="'));
    }

    public function test_a_single_table_account_has_no_group_label_and_singular_guest(): void
    {
        $this->abrir($this->mesero, ['Ventana 1'], 1);

        $this->actingAs($this->mesero)->get(route('panel.mesas.index'))
            ->assertOk()
            ->assertSee('Menos de 1 min · 1 comensal')
            ->assertDontSee('data-mesa-grupo', false);
    }

    public function test_the_admin_gets_the_link_to_edit_and_others_never_get_the_edit_mode(): void
    {
        $this->actingAs($this->admin)->get(route('panel.mesas.index'))
            ->assertOk()->assertSee('Editar salón')->assertSee(route('panel.mesas.index', ['editar' => 1]), false)->assertSee('data-plano', false);

        $this->actingAs($this->admin)->get(route('panel.mesas.index', ['editar' => 1]))
            ->assertOk()->assertSee('Volver al plano')->assertSee('Agregar varias')->assertDontSee('data-plano', false);

        foreach ([$this->cajero, $this->mesero] as $usuario) {
            $this->actingAs($usuario)->get(route('panel.mesas.index', ['editar' => 1]))
                ->assertOk()->assertSee('data-plano', false)->assertDontSee('Agregar varias')->assertDontSee('data-mesa-editar', false);
        }
    }

    public function test_the_live_endpoint_returns_every_table_in_use_already_rendered(): void
    {
        $inactiva = Mesa::factory()->for($this->salon)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'Guardada', 'activa' => false]);
        $areaInactiva = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Patio', 'activa' => false]);
        $oculta = Mesa::factory()->for($areaInactiva)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'Patio 1']);
        $cuenta = $this->abrir($this->mesero, ['Ventana 1', 'Ventana 2'], 3);
        $this->mesas['Ventana 4']->forceFill(['estado' => 'fuera_servicio'])->save();
        $this->pasan(125);

        $respuesta = $this->actingAs($this->mesero)->getJson(route('panel.mesas.plano'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJsonStructure(['mesas', 'resumen' => ['disponible', 'ocupada', 'fuera_servicio', 'total']])
            ->assertJsonPath('resumen', ['disponible' => 1, 'ocupada' => 2, 'fuera_servicio' => 1, 'total' => 4]);

        $mesas = $respuesta->json('mesas');
        $this->assertEqualsCanonicalizing(collect($this->mesas)->pluck('id')->all(), array_keys($mesas));
        $this->assertArrayNotHasKey($inactiva->id, $mesas);
        $this->assertArrayNotHasKey($oculta->id, $mesas);

        $ocupada = $mesas[$this->mesas['Ventana 2']->id];
        $this->assertStringContainsString('2 h 05 min · 3 comensales', $ocupada);
        $this->assertStringContainsString('Ventana 1 + Ventana 2', $ocupada);
        $this->assertStringContainsString(route('panel.mesas.cuentas.show', $cuenta), $ocupada);
        $this->assertStringContainsString('data-mesa-libre', $mesas[$this->mesas['Ventana 3']->id]);
        $this->assertStringContainsString('Fuera de servicio', $mesas[$this->mesas['Ventana 4']->id]);
        $this->assertStringNotContainsString('data-mesa-libre', $mesas[$this->mesas['Ventana 4']->id]);
    }

    public function test_the_page_and_the_live_endpoint_render_each_table_the_same(): void
    {
        $this->abrir($this->mesero, ['Ventana 1', 'Ventana 2'], 3);
        $this->actingAs($this->mesero);

        $pagina = preg_replace('/\s+/', ' ', $this->get(route('panel.mesas.index'))->getContent());

        foreach ($this->getJson(route('panel.mesas.plano'))->json('mesas') as $html) {
            $this->assertStringContainsString(trim(preg_replace('/\s+/', ' ', $html)), $pagina);
        }
    }

    /**
     * El plano se consulta cada 10 s desde cada dispositivo: sus consultas no pueden crecer con el salón.
     */
    public function test_the_live_endpoint_runs_a_fixed_number_of_queries(): void
    {
        $this->abrir($this->mesero, ['Ventana 1']);
        $pocas = $this->consultasDelPlano();

        // Un salón grande: 3 áreas más, 60 mesas más, 20 cuentas más (la mitad con mesas unidas) de 10 meseros.
        $meseros = User::factory()->count(10)->personal('mesero', $this->restaurante)->create();

        foreach (range(1, 3) as $a) {
            $area = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => "Área {$a}", 'orden' => $a]);

            foreach (range(1, 20) as $m) {
                $this->mesas["A{$a}-{$m}"] = Mesa::factory()->for($area)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => "A{$a}-{$m}", 'orden' => $m]);
            }
        }

        foreach (range(1, 10) as $n) {
            $this->abrir($meseros[$n - 1], ["A1-{$n}"]);
            $this->abrir($meseros[$n - 1], ["A2-{$n}", "A3-{$n}"]);
        }

        $muchas = $this->consultasDelPlano();

        $this->assertSame(64, count($this->getJson(route('panel.mesas.plano'))->json('mesas')));
        $this->assertSame($pocas, $muchas, "El plano hizo {$pocas} consultas con 4 mesas y {$muchas} con 64.");
        $this->assertLessThanOrEqual(8, $muchas);
    }

    private function consultasDelPlano(): int
    {
        // Usuario recién cargado: sin relaciones en memoria de una petición anterior.
        $this->actingAs($this->mesero->fresh());

        $consultas = 0;
        DB::listen(function () use (&$consultas): void {
            $consultas++;
        });

        $antes = $consultas;
        $this->getJson(route('panel.mesas.plano'))->assertOk();
        $total = $consultas - $antes;

        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

        return $total;
    }

    public function test_accounts_open_for_more_than_six_hours_are_highlighted_but_never_closed(): void
    {
        $this->assertSame(6, GestionarCuentas::HORAS_PARA_RESALTAR);

        $cuenta = $this->abrir($this->mesero, ['Ventana 1']);
        $this->actingAs($this->mesero);

        $this->pasan(359);
        $this->get(route('panel.mesas.index'))->assertSee('5 h 59 min')->assertDontSee('Más de 6 h abierta');
        $this->assertStringNotContainsString('Más de 6 h abierta', $this->getJson(route('panel.mesas.plano'))->json('mesas.'.$this->mesas['Ventana 1']->id));

        $this->pasan(1);
        $this->get(route('panel.mesas.index'))->assertSee('6 h 00 min')->assertSee('Más de 6 h abierta')->assertSee('outline-danger-dot');
        $this->assertStringContainsString('Más de 6 h abierta', $this->getJson(route('panel.mesas.plano'))->json('mesas.'.$this->mesas['Ventana 1']->id));
        $this->get(route('panel.mesas.cuentas.show', $cuenta))->assertSee('Más de 6 h abierta');

        $this->pasan(60 * 48);
        $this->get(route('panel.mesas.index'))->assertOk();
        $this->assertSame(['abierta', 1], [$cuenta->fresh()->estado, $cuenta->fresh()->version]);
        $this->assertSame('ocupada', $this->mesas['Ventana 1']->fresh()->estado);
    }

    public function test_the_account_detail_is_a_fragment_for_the_sheet_and_a_page_otherwise(): void
    {
        $cuenta = $this->abrir($this->mesero, ['Ventana 1', 'Ventana 2'], 5);
        $this->actingAs($this->cajero);

        $fragmento = $this->get(route('panel.mesas.cuentas.show', $cuenta), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $fragmento
            ->assertDontSee('<html', false)
            ->assertSee('data-titulo="Cuenta #1"', false)
            ->assertSee('data-version="1"', false)
            ->assertSee('Ventana 1 + Ventana 2')
            ->assertSee('Luis Chávez');

        $this->get(route('panel.mesas.cuentas.show', $cuenta))
            ->assertOk()->assertSee('<html', false)->assertSee('Volver al plano')->assertSee('data-version="1"', false);
    }

    public function test_the_detail_offers_each_role_only_its_actions(): void
    {
        $delMesero = $this->abrir($this->mesero, ['Ventana 1', 'Ventana 2']);
        $delCajero = $this->abrir($this->cajero, ['Ventana 3']);

        $this->actingAs($this->cajero)->get(route('panel.mesas.cuentas.show', $delMesero))
            ->assertSee('Agregar una mesa')->assertSee('Quitar una mesa')->assertSee('Mover de mesa')
            ->assertSee('Unir con otra cuenta')->assertSee('Transferir a otra persona')
            ->assertSee('Cerrar: cobrado en otro sistema')->assertSee('Anular cuenta')
            ->assertSee('value="'.$delCajero->id.':1"', false);

        $this->actingAs($this->mesero)->get(route('panel.mesas.cuentas.show', $delMesero))
            ->assertSee('Agregar una mesa')->assertSee('Anular cuenta')->assertDontSee('Cerrar: cobrado en otro sistema');

        // Cuenta de otra persona y de una sola mesa: el mesero no la anula ni puede quitarle su única mesa.
        $this->actingAs($this->mesero)->get(route('panel.mesas.cuentas.show', $delCajero))
            ->assertSee('Mover de mesa')->assertDontSee('Anular cuenta')->assertDontSee('Quitar una mesa')->assertDontSee('Cerrar: cobrado en otro sistema');
    }

    public function test_the_detail_of_a_finished_account_only_informs(): void
    {
        $cerrada = $this->abrir($this->mesero, ['Ventana 1']);
        $anulada = $this->abrir($this->mesero, ['Ventana 2']);
        $gestion = app(GestionarCuentas::class);
        $gestion->cerrarExterno($this->cajero, $cerrada, 1, 0);
        $gestion->anular($this->admin, $anulada, 1, 'Se abrió por error');

        $this->actingAs($this->cajero);

        $this->get(route('panel.mesas.cuentas.show', $cerrada))
            ->assertOk()->assertSee('Cerrada')->assertSee('Cobrado en otro sistema')->assertSee('Miguel Torres')->assertDontSee('data-cuenta-form', false);
        $this->get(route('panel.mesas.cuentas.show', $anulada))
            ->assertOk()->assertSee('Anulada')->assertSee('Se abrió por error')->assertDontSee('data-cuenta-form', false);
    }

    public function test_home_shows_occupied_tables_to_admin_and_cashier(): void
    {
        Mesa::factory()->for($this->salon)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'Guardada', 'activa' => false]);
        $patio = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Patio', 'activa' => false]);
        Mesa::factory()->for($patio)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'Patio 1']);
        $this->abrir($this->mesero, ['Ventana 1', 'Ventana 2']);

        foreach ([$this->admin, $this->cajero] as $usuario) {
            $this->actingAs($usuario)->get(route('panel.inicio'))
                ->assertOk()
                ->assertSee('Mesas ocupadas')
                ->assertSee('data-tarjeta="mesas-ocupadas"', false)
                ->assertSeeInOrder(['Mesas ocupadas', '2/4', '2 sin ocupar']);
        }
    }

    public function test_home_hides_the_card_without_the_module(): void
    {
        $this->restaurante->forceFill(['modulos' => null])->save();

        $this->actingAs($this->admin->fresh())->get(route('panel.inicio'))->assertOk()->assertDontSee('Mesas ocupadas');

        $this->restaurante->forceFill(['modulos' => ['mesas']])->save();
        config(['foora.modulos.mesas' => false]);

        $this->actingAs($this->admin->fresh())->get(route('panel.inicio'))->assertOk()->assertDontSee('Mesas ocupadas');
    }

    public function test_deactivating_someone_with_open_accounts_is_allowed_and_warns_with_the_count(): void
    {
        $this->abrir($this->mesero, ['Ventana 1']);
        $this->abrir($this->mesero, ['Ventana 2']);
        $cerrada = $this->abrir($this->mesero, ['Ventana 3']);
        app(GestionarCuentas::class)->cerrarExterno($this->cajero, $cerrada, 1, 0);

        $this->actingAs($this->admin)
            ->patchJson(route('panel.configuracion.usuarios.estado', $this->mesero->id), ['activo' => 0])
            ->assertOk()
            ->assertJsonPath('usuario.activo', false)
            ->assertJsonPath('cuentas_abiertas', 2)
            ->assertJsonPath('aviso', 'Luis Chávez tiene 2 cuentas de salón abiertas a su nombre. Transfiérelas a otra persona desde Mesas.')
            ->assertJsonPath('mensaje', 'La cuenta de Luis Chávez quedó desactivada y se cerraron sus sesiones. Luis Chávez tiene 2 cuentas de salón abiertas a su nombre. Transfiérelas a otra persona desde Mesas.');

        $this->assertFalse($this->mesero->fresh()->activo);
        $this->assertSame(2, Cuenta::abiertas()->where('mesero_id', $this->mesero->id)->count(), 'Las cuentas siguen abiertas a su nombre.');

        // El admin puede transferirlas después.
        $cuenta = Cuenta::abiertas()->firstOrFail();
        $this->patchJson(route('panel.mesas.cuentas.transferir', $cuenta), ['version' => 1, 'mesero_id' => $this->cajero->id])->assertOk();
    }

    public function test_deactivating_someone_without_open_accounts_does_not_warn(): void
    {
        $this->actingAs($this->admin)
            ->patchJson(route('panel.configuracion.usuarios.estado', $this->mesero->id), ['activo' => 0])
            ->assertOk()
            ->assertJsonMissingPath('aviso')
            ->assertJsonPath('mensaje', 'La cuenta de Luis Chávez quedó desactivada y se cerraron sus sesiones.');
    }

    public function test_without_javascript_the_warning_travels_in_the_flash_message(): void
    {
        $this->abrir($this->mesero, ['Ventana 1']);

        $this->actingAs($this->admin)
            ->patch(route('panel.configuracion.usuarios.estado', $this->mesero->id), ['activo' => 0])
            ->assertRedirect(route('panel.configuracion.usuarios'))
            ->assertSessionHas('status', 'La cuenta de Luis Chávez quedó desactivada y se cerraron sus sesiones. Luis Chávez tiene 1 cuenta de salón abierta a su nombre. Transfiérela a otra persona desde Mesas.');
    }
}
