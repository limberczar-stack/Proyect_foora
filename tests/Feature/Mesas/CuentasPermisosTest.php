<?php

namespace Tests\Feature\Mesas;

use App\Http\Middleware\EnsureLocalOperativo;
use App\Models\Area;
use App\Models\Cuenta;
use App\Models\Mesa;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\User;
use App\Services\GestionarCuentas;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Quién puede hacer qué con las cuentas, que nada cruce entre locales, el módulo apagado
 * y el local fuera de operación.
 */
class CuentasPermisosTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    private User $cajero;

    private User $mesero;

    private Area $salon;

    /** @var list<Mesa> */
    private array $mesas = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'America/Lima'));

        $this->restaurante = Restaurante::factory()->conModulos('mesas')->create();
        $this->admin = User::factory()->admin($this->restaurante)->create();
        $this->cajero = User::factory()->cajero($this->restaurante)->create();
        $this->mesero = User::factory()->personal('mesero', $this->restaurante)->create();
        $this->salon = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Salón']);

        foreach (range(0, 5) as $n) {
            $this->mesas[] = Mesa::factory()->for($this->salon)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'S'.($n + 1), 'orden' => $n]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<int>  $indices  posiciones en $this->mesas
     */
    private function cuentaDe(User $quien, array $indices): Cuenta
    {
        return app(GestionarCuentas::class)->abrir($quien, array_map(fn (int $i): int => $this->mesas[$i]->id, $indices), 2);
    }

    /**
     * Cada operación sobre una cuenta con datos válidos. Las mesas 4 y 5 quedan libres para usarlas.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function operaciones(Cuenta $cuenta, Cuenta $otra, int $mesaDeLaCuenta): array
    {
        $v = ['version' => $cuenta->version];

        return [
            'agregar' => ['post', route('panel.mesas.cuentas.mesas.agregar', $cuenta), [...$v, 'mesa_id' => $this->mesas[4]->id]],
            'quitar' => ['patch', route('panel.mesas.cuentas.mesas.quitar', $cuenta), [...$v, 'mesa_id' => $mesaDeLaCuenta]],
            'mover' => ['patch', route('panel.mesas.cuentas.mesas.mover', $cuenta), [...$v, 'desde_mesa_id' => $mesaDeLaCuenta, 'hacia_mesa_id' => $this->mesas[5]->id]],
            'fusionar' => ['patch', route('panel.mesas.cuentas.fusionar', $cuenta), [...$v, 'otra_cuenta_id' => $otra->id, 'otra_version' => $otra->version]],
            'transferir' => ['patch', route('panel.mesas.cuentas.transferir', $cuenta), [...$v, 'mesero_id' => $this->cajero->id]],
            'cerrar' => ['patch', route('panel.mesas.cuentas.cerrar', $cuenta), [...$v, 'total_centimos' => 0]],
            'anular' => ['patch', route('panel.mesas.cuentas.anular', $cuenta), [...$v, 'motivo' => 'Se abrió por error']],
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function enviar(string $metodo, string $url, array $datos): TestResponse
    {
        return $this->{$metodo.'Json'}($url, $datos);
    }

    private function assertSinCambios(Cuenta ...$cuentas): void
    {
        foreach ($cuentas as $cuenta) {
            $this->assertSame(['abierta', 1], [$cuenta->fresh()->estado, $cuenta->fresh()->version]);
        }

        $this->assertSame([], GestionarCuentas::mesasInconsistentes($this->restaurante->id));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesQueAtienden(): array
    {
        return ['admin' => ['admin'], 'cajero' => ['cajero'], 'mesero' => ['mesero']];
    }

    #[DataProvider('rolesQueAtienden')]
    public function test_floor_staff_open_and_modify_any_account_of_the_restaurant(string $rol): void
    {
        $ajena = $this->cuentaDe(User::factory()->personal('mesero', $this->restaurante)->create(), [0, 1]);
        $this->actingAs($this->{$rol});

        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => [$this->mesas[2]->id], 'comensales' => 2])->assertOk();
        $this->getJson(route('panel.mesas.plano'))->assertOk();
        $this->get(route('panel.mesas.cuentas.show', $ajena))->assertOk();

        $this->postJson(route('panel.mesas.cuentas.mesas.agregar', $ajena), ['version' => 1, 'mesa_id' => $this->mesas[3]->id])->assertOk();
        $this->patchJson(route('panel.mesas.cuentas.mesas.quitar', $ajena), ['version' => 2, 'mesa_id' => $this->mesas[3]->id])->assertOk();
        $this->patchJson(route('panel.mesas.cuentas.mesas.mover', $ajena), ['version' => 3, 'desde_mesa_id' => $this->mesas[1]->id, 'hacia_mesa_id' => $this->mesas[4]->id])->assertOk();
        $this->patchJson(route('panel.mesas.cuentas.transferir', $ajena), ['version' => 4, 'mesero_id' => $this->cajero->id])->assertOk();

        $propia = Cuenta::where('numero', 2)->sole();
        $this->patchJson(route('panel.mesas.cuentas.fusionar', $ajena), ['version' => 5, 'otra_cuenta_id' => $propia->id, 'otra_version' => 1])->assertOk();

        $this->assertSame([], GestionarCuentas::mesasInconsistentes($this->restaurante->id));
    }

    public function test_only_admin_and_cashier_close(): void
    {
        $cuenta = $this->cuentaDe($this->mesero, [0]);

        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => 0])->assertForbidden();
        $this->assertSinCambios($cuenta);

        $this->actingAs($this->cajero)->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => 0])->assertOk();
        $this->assertSame('cerrada', $cuenta->fresh()->estado);

        $otra = $this->cuentaDe($this->mesero, [1]);
        $this->actingAs($this->admin)->patchJson(route('panel.mesas.cuentas.cerrar', $otra), ['version' => 1, 'total_centimos' => 0])->assertOk();
    }

    public function test_the_waiter_voids_only_an_account_of_his_own(): void
    {
        $suya = $this->cuentaDe($this->mesero, [0]);
        $ajena = $this->cuentaDe($this->cajero, [1]);
        $this->actingAs($this->mesero);

        $this->patchJson(route('panel.mesas.cuentas.anular', $ajena), ['version' => 1, 'motivo' => 'No es mía'])->assertForbidden();
        $this->assertSinCambios($ajena);

        $this->patchJson(route('panel.mesas.cuentas.anular', $suya), ['version' => 1, 'motivo' => 'Se abrió por error'])->assertOk();
        $this->assertSame(['anulada', $this->mesero->id], [$suya->fresh()->estado, $suya->fresh()->anulada_por]);
    }

    public function test_a_transferred_account_becomes_voidable_by_its_new_waiter_only(): void
    {
        $cuenta = $this->cuentaDe($this->mesero, [0]);
        $otroMesero = User::factory()->personal('mesero', $this->restaurante)->create();

        $this->actingAs($this->mesero)->patchJson(route('panel.mesas.cuentas.transferir', $cuenta), ['version' => 1, 'mesero_id' => $otroMesero->id])->assertOk();

        $this->patchJson(route('panel.mesas.cuentas.anular', $cuenta), ['version' => 2, 'motivo' => 'Ya no es mía'])->assertForbidden();
        $this->actingAs($otroMesero)->patchJson(route('panel.mesas.cuentas.anular', $cuenta), ['version' => 2, 'motivo' => 'Se fueron'])->assertOk();
    }

    public function test_admin_and_cashier_void_any_account(): void
    {
        foreach ([$this->admin, $this->cajero] as $indice => $quien) {
            $cuenta = $this->cuentaDe($this->mesero, [$indice]);

            $this->actingAs($quien)->patchJson(route('panel.mesas.cuentas.anular', $cuenta), ['version' => 1, 'motivo' => 'Se fueron sin pedir'])->assertOk();
            $this->assertSame(['anulada', $quien->id], [$cuenta->fresh()->estado, $cuenta->fresh()->anulada_por]);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesSinSalon(): array
    {
        return ['cocina' => ['cocina'], 'repartidor' => ['repartidor']];
    }

    #[DataProvider('rolesSinSalon')]
    public function test_kitchen_and_delivery_get_403(string $rol): void
    {
        $cuenta = $this->cuentaDe($this->mesero, [0, 1]);
        $otra = $this->cuentaDe($this->mesero, [2]);
        $this->actingAs(User::factory()->personal($rol, $this->restaurante)->create());

        $this->getJson(route('panel.mesas.plano'))->assertForbidden();
        $this->get(route('panel.mesas.cuentas.show', $cuenta))->assertForbidden();
        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => [$this->mesas[3]->id], 'comensales' => 2])->assertForbidden();

        foreach ($this->operaciones($cuenta, $otra, $this->mesas[1]->id) as $accion => [$metodo, $url, $datos]) {
            $this->assertSame(403, $this->enviar($metodo, $url, $datos)->getStatusCode(), "{$rol} pudo: {$accion}");
        }

        $this->assertSinCambios($cuenta, $otra);
        $this->assertSame(2, Cuenta::count());
    }

    public function test_accounts_of_another_restaurant_are_404(): void
    {
        $otroLocal = Restaurante::factory()->conModulos('mesas')->create();
        $otroAdmin = User::factory()->admin($otroLocal)->create();
        $areaAjena = Area::factory()->create(['restaurante_id' => $otroLocal->id, 'nombre' => 'Salón ajeno']);
        $mesasAjenas = Mesa::factory()->count(2)->for($areaAjena)->create(['restaurante_id' => $otroLocal->id]);
        $ajena = app(GestionarCuentas::class)->abrir($otroAdmin, [$mesasAjenas[0]->id], 2);
        $propia = $this->cuentaDe($this->mesero, [0]);

        $this->actingAs($this->admin);

        $this->get(route('panel.mesas.cuentas.show', $ajena))->assertNotFound();

        foreach ($this->operaciones($ajena, $propia, $mesasAjenas[0]->id) as $accion => [$metodo, $url, $datos]) {
            $this->assertSame(404, $this->enviar($metodo, $url, $datos)->getStatusCode(), "Se pudo: {$accion} en otro local");
        }

        // Desde una cuenta propia tampoco se alcanza nada del otro local.
        $this->patchJson(route('panel.mesas.cuentas.fusionar', $propia), ['version' => 1, 'otra_cuenta_id' => $ajena->id, 'otra_version' => 1])->assertNotFound();
        $this->postJson(route('panel.mesas.cuentas.mesas.agregar', $propia), ['version' => 1, 'mesa_id' => $mesasAjenas[1]->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['mesas' => 'Elige mesas de tu local.']);
        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => [$mesasAjenas[1]->id], 'comensales' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('mesas.0');
        $this->patchJson(route('panel.mesas.cuentas.transferir', $propia), ['version' => 1, 'mesero_id' => $otroAdmin->id])->assertUnprocessable();

        $this->assertSame(['abierta', 1], [$ajena->fresh()->estado, $ajena->fresh()->version]);
        $this->assertSame('disponible', $mesasAjenas[1]->fresh()->estado);
        $this->assertSinCambios($propia);
        $this->assertSame([], GestionarCuentas::mesasInconsistentes($otroLocal->id));

        $this->getJson(route('panel.mesas.plano'))->assertOk()->assertJsonMissingPath('mesas.'.$mesasAjenas[0]->id);
    }

    public function test_module_off_answers_404_everywhere(): void
    {
        $cuenta = $this->cuentaDe($this->mesero, [0, 1]);
        $otra = $this->cuentaDe($this->mesero, [2]);
        $this->restaurante->forceFill(['modulos' => null])->save();
        $this->actingAs($this->admin->fresh());

        $this->getJson(route('panel.mesas.plano'))->assertNotFound();
        $this->get(route('panel.mesas.cuentas.show', $cuenta))->assertNotFound();
        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => [$this->mesas[3]->id], 'comensales' => 2])->assertNotFound();

        foreach ($this->operaciones($cuenta, $otra, $this->mesas[1]->id) as $accion => [$metodo, $url, $datos]) {
            $this->assertSame(404, $this->enviar($metodo, $url, $datos)->getStatusCode(), "Con el módulo apagado se pudo: {$accion}");
        }

        $this->assertSinCambios($cuenta, $otra);
    }

    public function test_out_of_operation_only_lets_close_and_void_existing_accounts(): void
    {
        $cuenta = $this->cuentaDe($this->mesero, [0, 1]);
        $otra = $this->cuentaDe($this->mesero, [2]);
        Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'vencida', 'vence_el' => '2026-10-01']);
        $this->assertFalse($this->restaurante->fresh()->operativo());
        $this->actingAs($this->admin->fresh());

        $this->postJson(route('panel.mesas.cuentas.store'), ['mesas' => [$this->mesas[3]->id], 'comensales' => 2])
            ->assertForbidden()->assertJsonPath('message', EnsureLocalOperativo::MENSAJE);

        foreach ($this->operaciones($cuenta, $otra, $this->mesas[1]->id) as $accion => [$metodo, $url, $datos]) {
            if (in_array($accion, ['cerrar', 'anular'], true)) {
                continue;
            }

            $this->enviar($metodo, $url, $datos)->assertForbidden()->assertJsonPath('message', EnsureLocalOperativo::MENSAJE);
        }

        $this->assertSinCambios($cuenta, $otra);
        $this->assertSame(2, Cuenta::count());

        // Ver sigue permitido, y lo que estaba abierto se puede terminar.
        $this->getJson(route('panel.mesas.plano'))->assertOk();
        $this->get(route('panel.mesas.cuentas.show', $cuenta))->assertOk();
        $this->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => 0])->assertOk();
        $this->patchJson(route('panel.mesas.cuentas.anular', $otra), ['version' => 1, 'motivo' => 'Local fuera de operación'])->assertOk();

        $this->assertSame(['cerrada', 'anulada'], [$cuenta->fresh()->estado, $otra->fresh()->estado]);
        $this->assertSame(0, Mesa::where('estado', 'ocupada')->count());
        $this->assertSame([], GestionarCuentas::mesasInconsistentes($this->restaurante->id));
    }

    public function test_superadmin_and_guests_do_not_reach_the_accounts(): void
    {
        $cuenta = $this->cuentaDe($this->mesero, [0]);

        $this->getJson(route('panel.mesas.plano'))->assertUnauthorized();
        $this->actingAs(User::factory()->superadmin()->create());
        $this->getJson(route('panel.mesas.plano'))->assertNotFound();
        $this->patchJson(route('panel.mesas.cuentas.cerrar', $cuenta), ['version' => 1, 'total_centimos' => 0])->assertNotFound();

        $this->assertSame('abierta', $cuenta->fresh()->estado);
    }
}
