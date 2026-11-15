<?php

namespace Tests\Feature\Mesas;

use App\Models\ActividadLog;
use App\Models\Area;
use App\Models\Cuenta;
use App\Models\Mesa;
use App\Models\OcupacionMesa;
use App\Models\Restaurante;
use App\Models\User;
use App\Services\GestionarCuentas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Operaciones sobre cuentas de salón. Después de cada una se comprueba el invariante:
 * una mesa está "ocupada" si y solo si tiene una ocupación abierta.
 */
class CuentasTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    private User $mesero;

    private Area $salon;

    private Area $terraza;

    /** @var array<string, Mesa> código => mesa */
    private array $mesas = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->conModulos('mesas')->create();
        $this->admin = User::factory()->admin($this->restaurante)->create(['name' => 'Rosa Admin']);
        $this->mesero = User::factory()->personal('mesero', $this->restaurante)->create(['name' => 'Luis Mesero']);
        $this->salon = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Salón principal', 'orden' => 0]);
        $this->terraza = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Terraza', 'orden' => 1]);

        foreach (['1', '2', '3', '4'] as $orden => $codigo) {
            $this->mesas[$codigo] = $this->mesa($codigo, $this->salon, $orden);
        }

        $this->mesas['T1'] = $this->mesa('T1', $this->terraza, 0);
        $this->actingAs($this->admin);
    }

    private function mesa(string $codigo, Area $area, int $orden = 0): Mesa
    {
        return Mesa::factory()->for($area)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => $codigo, 'capacidad' => 4, 'orden' => $orden]);
    }

    /**
     * @param  list<string>  $codigos
     */
    private function abrir(array $codigos, int $comensales = 2): Cuenta
    {
        $this->postJson(route('panel.mesas.cuentas.store'), [
            'mesas' => array_map(fn (string $codigo): int => $this->mesas[$codigo]->id, $codigos),
            'comensales' => $comensales,
        ])->assertOk();

        return Cuenta::orderByDesc('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function operar(string $ruta, Cuenta $cuenta, array $datos = [], ?int $version = null): TestResponse
    {
        $metodo = $ruta === 'mesas.agregar' ? 'postJson' : 'patchJson';

        // Cerrar lleva el total que se ve: estas cuentas no tienen pedidos, así que es 0.
        if ($ruta === 'cerrar') {
            $datos += ['total_centimos' => 0];
        }

        return $this->{$metodo}(route('panel.mesas.cuentas.'.$ruta, $cuenta), ['version' => $version ?? $cuenta->fresh()->version, ...$datos]);
    }

    private function estado(string $codigo): string
    {
        return $this->mesas[$codigo]->fresh()->estado;
    }

    /**
     * @return list<string> códigos de las mesas que la cuenta ocupa ahora
     */
    private function mesasDe(Cuenta $cuenta): array
    {
        return $cuenta->ocupacionesAbiertas()->with('mesa')->get()->pluck('mesa.codigo')->all();
    }

    /**
     * Comprobación propia, sin usar el código del servicio: ocupada si y solo si tiene una (y solo una) ocupación abierta.
     */
    private function assertInvariante(): void
    {
        foreach (Mesa::withoutGlobalScopes()->get() as $mesa) {
            $abiertas = OcupacionMesa::withoutGlobalScopes()->where('mesa_id', $mesa->id)->whereNull('salida_at')->count();

            $this->assertSame($mesa->estado === 'ocupada' ? 1 : 0, $abiertas, "Mesa {$mesa->codigo}: estado {$mesa->estado} con {$abiertas} ocupaciones abiertas.");
        }

        $this->assertSame([], GestionarCuentas::mesasInconsistentes($this->restaurante->id));

        // Solo las cuentas abiertas ocupan mesas.
        $this->assertSame(0, OcupacionMesa::whereNull('salida_at')->whereHas('cuenta', fn ($q) => $q->whereNotIn('estado', Cuenta::ESTADOS_ABIERTOS))->count());
    }

    public function test_opening_an_account_occupies_the_table(): void
    {
        $this->actingAs($this->mesero)
            ->postJson(route('panel.mesas.cuentas.store'), ['mesas' => [$this->mesas['1']->id], 'comensales' => 3, 'nota' => '  Cumpleaños  '])
            ->assertOk()
            ->assertJsonPath('mensaje', 'Se abrió la cuenta #1.');

        $cuenta = Cuenta::sole();
        $this->assertSame([1, 'abierta', 1, $this->mesero->id, 3, 'Cumpleaños'], [$cuenta->numero, $cuenta->estado, $cuenta->version, $cuenta->mesero_id, $cuenta->comensales, $cuenta->nota]);
        $this->assertNotNull($cuenta->abierta_at);
        $this->assertSame('ocupada', $this->estado('1'));
        $this->assertSame(['1'], $this->mesasDe($cuenta));

        $ocupacion = OcupacionMesa::sole();
        $this->assertNull($ocupacion->personas);
        $this->assertNull($ocupacion->consumo_total);

        $log = ActividadLog::where('accion', 'abrio_cuenta')->sole();
        $this->assertSame(['mesas', $this->mesero->id, 'Abrió la cuenta #1 en la mesa 1.'], [$log->modulo, $log->user_id, $log->descripcion]);
        $this->assertInvariante();
    }

    public function test_opening_can_join_tables_from_any_area(): void
    {
        $cuenta = $this->abrir(['4', 'T1', '2'], 7);

        $this->assertSame(['4', 'T1', '2'], $this->mesasDe($cuenta));
        $this->assertSame(['ocupada', 'ocupada', 'ocupada', 'disponible'], [$this->estado('4'), $this->estado('T1'), $this->estado('2'), $this->estado('1')]);
        $this->assertSame('Abrió la cuenta #1 en las mesas 4 + T1 + 2.', ActividadLog::where('accion', 'abrio_cuenta')->sole()->descripcion);
        $this->assertInvariante();
    }

    public function test_numbers_are_consecutive_per_restaurant_and_voided_accounts_keep_theirs(): void
    {
        $otro = Restaurante::factory()->conModulos('mesas')->create();
        Cuenta::factory()->create(['restaurante_id' => $otro->id, 'numero' => 40]);

        $primera = $this->abrir(['1']);
        $this->operar('anular', $primera, ['motivo' => 'Se abrió por error'])->assertOk();
        $segunda = $this->abrir(['1']);
        $tercera = $this->abrir(['2']);

        $this->assertSame([1, 2, 3], [$primera->numero, $segunda->numero, $tercera->numero]);
        $this->assertSame([1, 2, 3], Cuenta::orderBy('numero')->pluck('numero')->all());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function aperturasInvalidas(): array
    {
        return [
            'sin mesas' => [['mesas' => []], 'mesas'],
            'comensales cero' => [['comensales' => 0], 'comensales'],
            'comensales 100' => [['comensales' => 100], 'comensales'],
            'comensales decimal' => [['comensales' => 2.5], 'comensales'],
            'nota larga' => [['nota' => str_repeat('a', 256)], 'nota'],
        ];
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    #[DataProvider('aperturasInvalidas')]
    public function test_invalid_openings_are_rejected(array $cambios, string $campo): void
    {
        $this->postJson(route('panel.mesas.cuentas.store'), [...['mesas' => [$this->mesas['1']->id], 'comensales' => 2], ...$cambios])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);

        $this->assertSame(0, Cuenta::count());
        $this->assertInvariante();
    }

    public function test_an_account_opens_with_at_most_ten_tables_and_never_repeats_one(): void
    {
        $ids = collect(range(1, 11))->map(fn (int $n): int => $this->mesa("X{$n}", $this->salon, 10 + $n)->id);

        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => $ids->all(), 'comensales' => 20])
            ->assertUnprocessable()->assertJsonValidationErrors(['mesas' => 'Una cuenta lleva hasta 10 mesas.']);
        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => [$ids[0], $ids[0]], 'comensales' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('mesas.0');
        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => $ids->take(10)->all(), 'comensales' => 20])->assertOk();

        $this->assertSame(10, Cuenta::sole()->ocupacionesAbiertas()->count());
        $this->assertInvariante();
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function mesasQueNoSePuedenOcupar(): array
    {
        return [
            'ocupada' => [['estado' => 'ocupada']],
            'fuera de servicio' => [['estado' => 'fuera_servicio']],
            'reservada' => [['estado' => 'reservada']],
            'desactivada' => [['activa' => false]],
        ];
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    #[DataProvider('mesasQueNoSePuedenOcupar')]
    public function test_a_table_that_is_not_available_gives_409_and_opens_nothing(array $cambios): void
    {
        // La mesa 2 cambió después de que el mesero cargó el plano.
        if (($cambios['estado'] ?? null) === 'ocupada') {
            $this->abrir(['2']);
        } else {
            $this->mesas['2']->forceFill($cambios)->save();
        }

        $antes = Cuenta::count();

        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => [$this->mesas['1']->id, $this->mesas['2']->id], 'comensales' => 2])
            ->assertConflict()
            ->assertJsonPath('message', 'La mesa 2 ya no está disponible. Actualizamos el plano.');

        $this->assertSame($antes, Cuenta::count());
        $this->assertSame('disponible', $this->estado('1'));
        $this->assertInvariante();
    }

    public function test_a_table_in_a_deactivated_area_cannot_be_occupied(): void
    {
        $this->terraza->update(['activa' => false]);

        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => [$this->mesas['T1']->id], 'comensales' => 2])->assertConflict();

        $this->assertSame(0, Cuenta::count());
    }

    public function test_the_number_of_a_failed_opening_is_not_consumed(): void
    {
        $this->abrir(['1']);
        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => [$this->mesas['1']->id], 'comensales' => 2])->assertConflict();

        $this->assertSame(2, $this->abrir(['2'])->numero);
    }

    /**
     * Otra petición ocupa la mesa entre la comprobación y el guardado: el índice uq_ocu_mesa_abierta lo
     * rechaza y la respuesta es 409, nunca 500. No queda media cuenta.
     */
    public function test_losing_the_race_to_the_unique_index_is_a_409_and_leaves_nothing_half_done(): void
    {
        $ajena = Cuenta::factory()->create(['restaurante_id' => $this->restaurante->id, 'numero' => 900]);
        $mesaId = $this->mesas['1']->id;
        $colada = false;

        OcupacionMesa::creating(function () use (&$colada, $ajena, $mesaId): void {
            if (! $colada) {
                $colada = true;
                DB::table('ocupaciones_mesa')->insert(['restaurante_id' => $ajena->restaurante_id, 'mesa_id' => $mesaId, 'cuenta_id' => $ajena->id, 'ingreso_at' => now()]);
            }
        });

        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => [$mesaId], 'comensales' => 2])
            ->assertConflict()
            ->assertJsonPath('message', GestionarCuentas::MENSAJE_INDICE);

        $this->assertSame(1, Cuenta::count());
        $this->assertSame(0, OcupacionMesa::count());
        $this->assertSame('disponible', $this->estado('1'));
    }

    public function test_adding_a_table(): void
    {
        $cuenta = $this->abrir(['1']);

        $this->operar('mesas.agregar', $cuenta, ['mesa_id' => $this->mesas['T1']->id])
            ->assertOk()->assertJsonPath('mensaje', 'Se agregó la mesa a la cuenta #1.');

        $this->assertSame(['1', 'T1'], $this->mesasDe($cuenta));
        $this->assertSame(2, $cuenta->fresh()->version);
        $this->assertSame(1, ActividadLog::where('accion', 'agrego_mesa_cuenta')->count());
        $this->assertInvariante();
    }

    public function test_adding_a_taken_table_or_with_an_old_version_gives_409(): void
    {
        $cuenta = $this->abrir(['1']);
        $otra = $this->abrir(['2']);

        $this->operar('mesas.agregar', $cuenta, ['mesa_id' => $this->mesas['2']->id])
            ->assertConflict()->assertJsonPath('message', 'La mesa 2 ya no está disponible. Actualizamos el plano.');

        $this->operar('mesas.agregar', $cuenta, ['mesa_id' => $this->mesas['3']->id])->assertOk();
        $this->operar('mesas.agregar', $cuenta, ['mesa_id' => $this->mesas['4']->id], version: 1)
            ->assertConflict()->assertJsonPath('message', GestionarCuentas::MENSAJE_DESACTUALIZADA);

        $this->assertSame(['1', '3'], $this->mesasDe($cuenta));
        $this->assertSame(['2'], $this->mesasDe($otra));
        $this->assertSame('disponible', $this->estado('4'));
        $this->assertInvariante();
    }

    public function test_an_account_never_passes_ten_tables_when_adding(): void
    {
        $ids = collect(range(1, 11))->map(fn (int $n): int => $this->mesa("X{$n}", $this->salon, 10 + $n)->id);
        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => $ids->take(10)->all(), 'comensales' => 20])->assertOk();

        $this->operar('mesas.agregar', Cuenta::sole(), ['mesa_id' => $ids[10]])
            ->assertUnprocessable()->assertJsonValidationErrors(['mesa_id' => 'Una cuenta lleva hasta 10 mesas.']);

        $this->assertInvariante();
    }

    public function test_removing_a_table_frees_it_but_the_account_keeps_at_least_one(): void
    {
        $cuenta = $this->abrir(['1', '2']);

        $this->operar('mesas.quitar', $cuenta, ['mesa_id' => $this->mesas['1']->id])->assertOk();

        $this->assertSame(['2'], $this->mesasDe($cuenta));
        $this->assertSame('disponible', $this->estado('1'));
        $this->assertInvariante();

        $this->operar('mesas.quitar', $cuenta, ['mesa_id' => $this->mesas['2']->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mesa_id' => 'La cuenta debe conservar al menos una mesa. Si ya se fueron, ciérrala o anúlala.']);
        $this->operar('mesas.quitar', $cuenta, ['mesa_id' => $this->mesas['3']->id])
            ->assertConflict()->assertJsonPath('message', 'Esa mesa ya no está en la cuenta. Actualizamos el plano.');

        $this->assertSame('ocupada', $this->estado('2'));
        $this->assertSame(2, OcupacionMesa::count(), 'La ocupación de la mesa quitada queda como historial.');
        $this->assertInvariante();
    }

    public function test_moving_to_another_table(): void
    {
        $cuenta = $this->abrir(['1', '2']);

        $this->operar('mesas.mover', $cuenta, ['desde_mesa_id' => $this->mesas['1']->id, 'hacia_mesa_id' => $this->mesas['T1']->id])
            ->assertOk()->assertJsonPath('mensaje', 'Se movió la cuenta #1 de mesa.');

        $this->assertSame(['2', 'T1'], $this->mesasDe($cuenta));
        $this->assertSame(['disponible', 'ocupada'], [$this->estado('1'), $this->estado('T1')]);
        $this->assertSame(['mesa' => '1'], ActividadLog::where('accion', 'movio_cuenta')->sole()->datos_antes);
        $this->assertInvariante();
    }

    public function test_moving_to_a_taken_table_or_from_a_table_not_in_the_account_gives_409(): void
    {
        $cuenta = $this->abrir(['1']);
        $this->abrir(['2']);

        $this->operar('mesas.mover', $cuenta, ['desde_mesa_id' => $this->mesas['1']->id, 'hacia_mesa_id' => $this->mesas['2']->id])->assertConflict();
        $this->operar('mesas.mover', $cuenta, ['desde_mesa_id' => $this->mesas['3']->id, 'hacia_mesa_id' => $this->mesas['4']->id])->assertConflict();
        $this->operar('mesas.mover', $cuenta, ['desde_mesa_id' => $this->mesas['1']->id, 'hacia_mesa_id' => $this->mesas['1']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('hacia_mesa_id');

        $this->assertSame(['1'], $this->mesasDe($cuenta));
        $this->assertSame(1, $cuenta->fresh()->version);
        $this->assertInvariante();
    }

    public function test_merging_two_open_accounts(): void
    {
        $principal = $this->abrir(['4'], 3);
        $otra = $this->actingAs($this->mesero)->abrir(['T1', '2'], 4);

        $this->actingAs($this->admin)
            ->operar('fusionar', $principal, ['otra_cuenta_id' => $otra->id, 'otra_version' => $otra->version])
            ->assertOk()->assertJsonPath('mensaje', 'La cuenta #2 se unió con la #1.');

        $principal->refresh();
        $otra->refresh();
        $this->assertSame(['4', 'T1', '2'], $this->mesasDe($principal));
        $this->assertSame([7, 2, $this->admin->id], [$principal->comensales, $principal->version, $principal->mesero_id]);
        $this->assertSame(['fusionada', $principal->id, 2, $this->admin->id], [$otra->estado, $otra->fusionada_en_id, $otra->version, $otra->cerrada_por]);
        $this->assertNotNull($otra->cerrada_at);
        $this->assertSame([], $this->mesasDe($otra), 'La cuenta fusionada ya no ocupa mesas.');
        $this->assertSame(['ocupada', 'ocupada', 'ocupada'], [$this->estado('4'), $this->estado('T1'), $this->estado('2')]);
        $this->assertSame(5, OcupacionMesa::count(), 'Las ocupaciones de la segunda quedan cerradas como historial.');
        $this->assertSame(2, ActividadLog::where('accion', 'fusiono_cuentas')->sole()->datos_despues['cuenta_fusionada']);
        $this->assertInvariante();
    }

    public function test_merging_accepts_the_single_field_the_form_sends(): void
    {
        $principal = $this->abrir(['1']);
        $otra = $this->abrir(['2']);

        $this->operar('fusionar', $principal, ['otra' => "{$otra->id}:{$otra->version}"])->assertOk();

        $this->assertSame('fusionada', $otra->fresh()->estado);
    }

    public function test_merging_gives_409_if_either_account_changed(): void
    {
        $principal = $this->abrir(['1']);
        $otra = $this->abrir(['2']);

        // Alguien agregó una mesa a la segunda mientras se veía la pantalla.
        $this->operar('mesas.agregar', $otra, ['mesa_id' => $this->mesas['3']->id])->assertOk();

        $this->operar('fusionar', $principal, ['otra_cuenta_id' => $otra->id, 'otra_version' => 1])
            ->assertConflict()->assertJsonPath('message', GestionarCuentas::MENSAJE_DESACTUALIZADA);

        // Y ahora cambia la primera.
        $this->operar('mesas.agregar', $principal, ['mesa_id' => $this->mesas['4']->id])->assertOk();

        $this->operar('fusionar', $principal, ['otra_cuenta_id' => $otra->id, 'otra_version' => 2], version: 1)
            ->assertConflict()->assertJsonPath('message', GestionarCuentas::MENSAJE_DESACTUALIZADA);

        $this->assertSame(['abierta', 'abierta'], [$principal->fresh()->estado, $otra->fresh()->estado]);
        $this->assertSame(['1', '4'], $this->mesasDe($principal));
        $this->assertSame(['2', '3'], $this->mesasDe($otra));
        $this->assertInvariante();
    }

    public function test_merging_rules(): void
    {
        $principal = $this->abrir(['1'], 60);
        $otra = $this->abrir(['2'], 60);
        $cerrada = $this->abrir(['3']);
        $this->operar('cerrar', $cerrada)->assertOk();

        $this->operar('fusionar', $principal, ['otra_cuenta_id' => $principal->id, 'otra_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('otra_cuenta_id');
        $this->operar('fusionar', $principal, ['otra_cuenta_id' => $cerrada->id, 'otra_version' => $cerrada->fresh()->version])
            ->assertConflict()->assertJsonPath('message', GestionarCuentas::MENSAJE_YA_NO_ABIERTA);
        $this->operar('fusionar', $principal, ['otra_cuenta_id' => 999999, 'otra_version' => 1])->assertNotFound();

        $this->operar('fusionar', $principal, ['otra_cuenta_id' => $otra->id, 'otra_version' => 1])->assertOk();
        $this->assertSame(99, $principal->fresh()->comensales, 'Los comensales se suman con tope de 99.');
        $this->assertInvariante();
    }

    public function test_merging_never_passes_ten_tables(): void
    {
        $ids = collect(range(1, 10))->map(fn (int $n): int => $this->mesa("X{$n}", $this->salon, 10 + $n)->id);
        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => $ids->all(), 'comensales' => 20])->assertOk();
        $grande = Cuenta::sole();
        $otra = $this->abrir(['1']);

        $this->operar('fusionar', $grande, ['otra_cuenta_id' => $otra->id, 'otra_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['otra_cuenta_id' => 'Juntas pasarían de 10 mesas. Quita alguna antes de unirlas.']);

        $this->assertSame('abierta', $otra->fresh()->estado);
        $this->assertInvariante();
    }

    public function test_transferring_to_another_person(): void
    {
        $cuenta = $this->abrir(['1']);

        $this->operar('transferir', $cuenta, ['mesero_id' => $this->mesero->id])
            ->assertOk()->assertJsonPath('mensaje', 'La cuenta #1 ahora es de Luis Mesero.');

        $this->assertSame([$this->mesero->id, 2], [$cuenta->fresh()->mesero_id, $cuenta->fresh()->version]);
        $log = ActividadLog::where('accion', 'transfirio_cuenta')->sole();
        $this->assertSame([['mesero_id' => $this->admin->id], ['mesero_id' => $this->mesero->id]], [$log->datos_antes, $log->datos_despues]);
    }

    public function test_transfers_only_go_to_active_floor_staff_of_the_restaurant(): void
    {
        $cuenta = $this->abrir(['1']);
        $cocina = User::factory()->personal('cocina', $this->restaurante)->create();
        $inactivo = User::factory()->personal('mesero', $this->restaurante)->inactivo()->create();
        $ajeno = User::factory()->personal('mesero')->create();

        foreach ([$cocina, $inactivo, $ajeno, $this->admin] as $destino) {
            $this->operar('transferir', $cuenta, ['mesero_id' => $destino->id])->assertUnprocessable()->assertJsonValidationErrors('mesero_id');
        }

        $this->assertSame([$this->admin->id, 1], [$cuenta->fresh()->mesero_id, $cuenta->fresh()->version]);
    }

    public function test_closing_as_charged_elsewhere_frees_every_table(): void
    {
        $cuenta = $this->abrir(['1', 'T1']);

        $this->operar('cerrar', $cuenta)
            ->assertOk()->assertJsonPath('mensaje', 'Se cerró la cuenta #1 y sus mesas quedaron disponibles.');

        $cuenta->refresh();
        $this->assertSame(['cerrada', 'externo', $this->admin->id, 2], [$cuenta->estado, $cuenta->cierre_tipo, $cuenta->cerrada_por, $cuenta->version]);
        $this->assertNotNull($cuenta->cerrada_at);
        $this->assertSame(['disponible', 'disponible'], [$this->estado('1'), $this->estado('T1')]);
        $this->assertSame(2, OcupacionMesa::whereNotNull('salida_at')->count(), 'Nada se borra: las ocupaciones quedan cerradas.');
        $this->assertSame(['1', 'T1'], ActividadLog::where('accion', 'cerro_cuenta')->sole()->datos_despues['mesas_liberadas']);
        $this->assertInvariante();

        // Las mesas vuelven a usarse.
        $this->assertSame(2, $this->abrir(['1'])->numero);
        $this->assertInvariante();
    }

    public function test_voiding_needs_a_reason_and_frees_every_table(): void
    {
        $cuenta = $this->abrir(['1', '2']);

        $this->operar('anular', $cuenta)->assertUnprocessable()->assertJsonValidationErrors(['motivo' => 'Escribe el motivo de la anulación.']);
        $this->operar('anular', $cuenta, ['motivo' => 'ab'])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->assertSame('abierta', $cuenta->fresh()->estado);

        $this->operar('anular', $cuenta, ['motivo' => 'Se abrió por error'])
            ->assertOk()->assertJsonPath('mensaje', 'Se anuló la cuenta #1 y sus mesas quedaron disponibles.');

        $cuenta->refresh();
        $this->assertSame(['anulada', $this->admin->id, 'Se abrió por error', 2], [$cuenta->estado, $cuenta->anulada_por, $cuenta->motivo_anulacion, $cuenta->version]);
        $this->assertNotNull($cuenta->anulada_at);
        $this->assertNull($cuenta->cierre_tipo);
        $this->assertSame(['disponible', 'disponible'], [$this->estado('1'), $this->estado('2')]);
        $this->assertModelExists($cuenta);
        $this->assertInvariante();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function finales(): array
    {
        return ['cerrada' => ['cerrar', []], 'anulada' => ['anular', ['motivo' => 'Se abrió por error']]];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    #[DataProvider('finales')]
    public function test_an_account_that_is_no_longer_open_rejects_every_operation_with_409(string $final, array $datos): void
    {
        $cuenta = $this->abrir(['1']);
        $otra = $this->abrir(['2']);
        $this->operar($final, $cuenta, $datos)->assertOk();

        $intentos = [
            ['mesas.agregar', ['mesa_id' => $this->mesas['3']->id]],
            ['mesas.quitar', ['mesa_id' => $this->mesas['1']->id]],
            ['mesas.mover', ['desde_mesa_id' => $this->mesas['1']->id, 'hacia_mesa_id' => $this->mesas['3']->id]],
            ['fusionar', ['otra_cuenta_id' => $otra->id, 'otra_version' => 1]],
            ['transferir', ['mesero_id' => $this->mesero->id]],
            ['cerrar', []],
            ['anular', ['motivo' => 'Otra vez']],
        ];

        foreach ($intentos as [$ruta, $extra]) {
            // Con la version actual (ya cerrada) y con la que se veía antes.
            $this->operar($ruta, $cuenta, $extra)->assertConflict()->assertJsonPath('message', GestionarCuentas::MENSAJE_YA_NO_ABIERTA);
            $this->operar($ruta, $cuenta, $extra, version: 1)->assertConflict()->assertJsonPath('message', GestionarCuentas::MENSAJE_DESACTUALIZADA);
        }

        $this->assertSame(2, $cuenta->fresh()->version);
        $this->assertSame('abierta', $otra->fresh()->estado);
        $this->assertInvariante();
    }

    public function test_every_operation_needs_the_version(): void
    {
        $cuenta = $this->abrir(['1']);

        $this->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), [])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->assertSame('abierta', $cuenta->fresh()->estado);
    }

    public function test_the_invariant_holds_through_a_whole_service(): void
    {
        $a = $this->abrir(['1', '2'], 4);
        $this->assertInvariante();
        $b = $this->abrir(['3']);
        $this->assertInvariante();

        $this->operar('mesas.agregar', $b, ['mesa_id' => $this->mesas['4']->id])->assertOk();
        $this->assertInvariante();
        $this->operar('mesas.mover', $a, ['desde_mesa_id' => $this->mesas['1']->id, 'hacia_mesa_id' => $this->mesas['T1']->id])->assertOk();
        $this->assertInvariante();
        $this->operar('mesas.quitar', $b, ['mesa_id' => $this->mesas['3']->id])->assertOk();
        $this->assertInvariante();
        $this->operar('fusionar', $a, ['otra_cuenta_id' => $b->id, 'otra_version' => $b->fresh()->version])->assertOk();
        $this->assertInvariante();
        $this->assertSame(['2', 'T1', '4'], $this->mesasDe($a));

        $c = $this->abrir(['1']);
        $this->operar('anular', $c, ['motivo' => 'Se fueron sin pedir'])->assertOk();
        $this->assertInvariante();
        $this->operar('cerrar', $a)->assertOk();
        $this->assertInvariante();

        $this->assertSame(0, Mesa::where('estado', 'ocupada')->count());
        $this->assertSame(['cerrada', 'fusionada', 'anulada'], Cuenta::orderBy('numero')->pluck('estado')->all());
        $this->assertSame(0, OcupacionMesa::whereNull('salida_at')->count());
    }

    public function test_without_javascript_a_conflict_goes_back_to_the_map_with_the_message(): void
    {
        $cuenta = $this->abrir(['1']);
        $this->operar('mesas.agregar', $cuenta, ['mesa_id' => $this->mesas['2']->id])->assertOk();

        $this->patch(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => 0])
            ->assertRedirect(route('panel.mesas.index'))
            ->assertSessionHas('error', GestionarCuentas::MENSAJE_DESACTUALIZADA);

        $this->patch(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 2, 'total_centimos' => 0])
            ->assertRedirect(route('panel.mesas.index'))
            ->assertSessionHas('status', 'Se cerró la cuenta #1 y sus mesas quedaron disponibles.');
    }

    /* ---------------------------------------------------------------------- */
    /* Reglas cruzadas con la gestión de mesas (fase A) */
    /* ---------------------------------------------------------------------- */

    public function test_an_occupied_table_cannot_go_out_of_service_or_be_deactivated(): void
    {
        $cuenta = $this->abrir(['1']);
        $mesa = $this->mesas['1'];

        $this->patchJson(route('panel.mesas.estado', $mesa), ['estado' => 'fuera_servicio'])
            ->assertUnprocessable()->assertJsonValidationErrors(['estado' => 'La mesa 1 está ocupada: no puedes cambiarla ahora.']);
        $this->patchJson(route('panel.mesas.estado', $mesa), ['estado' => 'disponible'])->assertUnprocessable();
        $this->patchJson(route('panel.mesas.activa', $mesa), ['activa' => 0])->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->putJson(route('panel.mesas.update', $mesa), ['codigo' => '1', 'capacidad' => 4, 'area_id' => $this->salon->id, 'estado' => 'fuera_servicio'])
            ->assertUnprocessable();

        $this->assertSame(['ocupada', true], [$mesa->fresh()->estado, $mesa->fresh()->activa]);
        $this->assertInvariante();

        // Al cerrar la cuenta, la mesa vuelve a poder gestionarse.
        $this->operar('cerrar', $cuenta)->assertOk();
        $this->patchJson(route('panel.mesas.estado', $mesa), ['estado' => 'fuera_servicio'])->assertOk();
        $this->assertInvariante();
    }

    public function test_an_area_with_occupied_tables_cannot_be_deactivated(): void
    {
        $cuenta = $this->abrir(['1', '2', 'T1']);

        $this->patchJson(route('panel.mesas.areas.activa', $this->salon), ['activa' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['area' => 'No puedes desactivar «Salón principal» porque tiene 2 mesas ocupadas. Cierra o mueve sus cuentas primero.']);
        $this->patchJson(route('panel.mesas.areas.activa', $this->terraza), ['activa' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['area' => 'No puedes desactivar «Terraza» porque tiene 1 mesa ocupada. Cierra o mueve sus cuentas primero.']);

        $this->assertTrue($this->salon->fresh()->activa);

        $this->operar('cerrar', $cuenta)->assertOk();
        $this->patchJson(route('panel.mesas.areas.activa', $this->terraza), ['activa' => 0])->assertOk();
        $this->assertFalse($this->terraza->fresh()->activa);
    }
}
