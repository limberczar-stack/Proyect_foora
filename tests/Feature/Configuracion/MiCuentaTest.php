<?php

namespace Tests\Feature\Configuracion;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MiCuentaTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->create();
    }

    public function test_user_changes_their_own_password(): void
    {
        $cajero = User::factory()->cajero($this->restaurante)->create();

        $this->actingAs($cajero)
            ->putJson(route('panel.mi-cuenta.password'), [
                'password_actual' => 'password',
                'password' => 'pollo-a-la-brasa',
                'password_confirmation' => 'pollo-a-la-brasa',
            ])
            ->assertOk()
            ->assertJsonPath('mensaje', 'Se cambió tu contraseña.');

        $this->assertTrue(Hash::check('pollo-a-la-brasa', $cajero->fresh()->password));
    }

    public function test_password_change_requires_the_current_one_8_characters_and_confirmation(): void
    {
        $cajero = User::factory()->cajero($this->restaurante)->create();
        $this->actingAs($cajero);

        $this->putJson(route('panel.mi-cuenta.password'), ['password_actual' => 'incorrecta', 'password' => 'nueva-clave-1', 'password_confirmation' => 'nueva-clave-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password_actual' => 'La contraseña actual no es correcta.']);

        $this->putJson(route('panel.mi-cuenta.password'), ['password_actual' => 'password', 'password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'La nueva contraseña debe tener al menos 8 caracteres.']);

        $this->putJson(route('panel.mi-cuenta.password'), ['password_actual' => 'password', 'password' => 'nueva-clave-1', 'password_confirmation' => 'otra-clave-2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'Las dos contraseñas nuevas no coinciden.']);

        $this->putJson(route('panel.mi-cuenta.password'), ['password_actual' => 'password', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password', $cajero->fresh()->password));
    }

    public function test_password_change_is_throttled(): void
    {
        $this->actingAs(User::factory()->cajero($this->restaurante)->create());

        foreach (range(1, 6) as $intento) {
            $this->putJson(route('panel.mi-cuenta.password'), ['password_actual' => 'mala-'.$intento, 'password' => 'nueva-clave', 'password_confirmation' => 'nueva-clave'])
                ->assertUnprocessable();
        }

        $this->putJson(route('panel.mi-cuenta.password'), ['password_actual' => 'password', 'password' => 'nueva-clave', 'password_confirmation' => 'nueva-clave'])
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Hiciste muchos intentos seguidos. Espera un minuto e inténtalo otra vez.');
    }

    public function test_temporary_password_locks_the_panel_until_it_is_changed(): void
    {
        $cajero = User::factory()->cajero($this->restaurante)->create();
        $cajero->forceFill(['debe_cambiar_password' => true])->save();

        $this->actingAs($cajero)
            ->get(route('panel.inicio'))
            ->assertRedirect(route('panel.mi-cuenta'))
            ->assertSessionHas('error', 'Antes de seguir, cambia la contraseña temporal que te dieron.');

        $this->get(route('panel.pedidos.index'))->assertRedirect(route('panel.mi-cuenta'));
        $this->getJson(route('panel.pedidos.novedades'))
            ->assertForbidden()
            ->assertJsonPath('redirect', route('panel.mi-cuenta'));
        $this->patchJson(route('panel.estado-local'), ['estado' => 'cerrado', 'confirmar' => '1'])->assertForbidden();
        $this->assertSame('abierto', $this->restaurante->fresh()->estado_local);

        $this->get(route('panel.mi-cuenta'))->assertOk()->assertSee('Estás usando una contraseña temporal.');

        $this->putJson(route('panel.mi-cuenta.password'), [
            'password_actual' => 'password',
            'password' => 'mi-clave-propia',
            'password_confirmation' => 'mi-clave-propia',
        ])
            ->assertOk()
            ->assertJsonPath('redirect', route('panel.inicio'));

        $this->assertFalse($cajero->fresh()->debe_cambiar_password);
        $this->get(route('panel.inicio'))->assertOk();
    }

    public function test_a_freshly_created_user_must_change_the_temporary_password_after_login(): void
    {
        $admin = User::factory()->admin($this->restaurante)->create();

        $temporal = $this->actingAs($admin)
            ->postJson(route('panel.configuracion.usuarios.store'), ['name' => 'Nuevo', 'email' => 'nuevo@local.pe', 'rol' => 'mesero'])
            ->json('password_temporal');

        $this->post(route('logout'));
        $this->app['auth']->forgetGuards();

        $this->post(route('login.store'), ['email' => 'nuevo@local.pe', 'password' => $temporal])->assertRedirect(route('panel.inicio'));
        $this->get(route('panel.inicio'))->assertRedirect(route('panel.mi-cuenta'));
    }

    public function test_closing_other_sessions_invalidates_the_other_device(): void
    {
        config(['session.driver' => 'database']);

        $admin = User::factory()->admin($this->restaurante)->create(['remember_token' => 'recordarme-viejo']);
        $otroDispositivo = $this->sesionGuardada($admin);
        $esteDispositivo = $this->sesionGuardada($admin);

        // El otro dispositivo está dentro.
        $this->conSesion($otroDispositivo)->get(route('panel.inicio'))->assertOk();

        // Desde este dispositivo se cierran las demás.
        $this->conSesion($esteDispositivo)
            ->deleteJson(route('panel.mi-cuenta.sesiones'), ['password_actual' => 'password'])
            ->assertOk()
            ->assertJsonPath('otras_sesiones', 0);

        $this->assertFalse(DB::table('sessions')->where('id', $otroDispositivo)->exists());
        $this->assertNull($admin->fresh()->remember_token);

        // El otro dispositivo quedó fuera; este sigue dentro.
        $this->conSesion($otroDispositivo)->get(route('panel.inicio'))->assertRedirect(route('login'));
        $this->conSesion($esteDispositivo)->get(route('panel.inicio'))->assertOk();
    }

    public function test_closing_other_sessions_requires_the_password(): void
    {
        $this->actingAs(User::factory()->admin($this->restaurante)->create())
            ->deleteJson(route('panel.mi-cuenta.sesiones'), ['password_actual' => 'mala'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password_actual');
    }

    /**
     * Una sesión iniciada en otro dispositivo, guardada como la guarda Laravel (JSON en base64).
     */
    private function sesionGuardada(User $usuario): string
    {
        $id = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $usuario->id,
            'ip_address' => '10.0.0.2',
            'user_agent' => 'Celular de prueba',
            'payload' => base64_encode(json_encode([
                '_token' => Str::random(40),
                'login_web_'.sha1(SessionGuard::class) => $usuario->id,
            ])),
            'last_activity' => now()->getTimestamp(),
        ]);

        return $id;
    }

    /**
     * Cada petición llega con su propia cookie de sesión y sin usuario recordado de la anterior.
     */
    private function conSesion(string $id): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('session.store');
        $this->app['session']->forgetDrivers();

        return $this->withCredentials()->withCookie(config('session.cookie'), $id);
    }
}
