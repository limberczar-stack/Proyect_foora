<?php

namespace Tests\Feature\Configuracion;

use App\Models\ActividadLog;
use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\User;
use App\Services\GestionarUsuarios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsuariosTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->create();
        $this->admin = User::factory()->admin($this->restaurante)->create(['name' => 'Rosa Admin']);
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function nuevo(array $cambios = []): array
    {
        return ['name' => 'Luis Cajero', 'email' => 'Luis@Local.pe', 'telefono' => '987654321', 'rol' => 'cajero', ...$cambios];
    }

    private function sesionDe(User $usuario): string
    {
        $id = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $usuario->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Otro dispositivo',
            'payload' => base64_encode(json_encode(['_token' => Str::random(40)])),
            'last_activity' => now()->getTimestamp(),
        ]);

        return $id;
    }

    private function conPlan(?int $maxUsuarios): void
    {
        $plan = Plan::create(['nombre' => 'Básico', 'precio_mensual' => 39, 'max_usuarios' => $maxUsuarios]);
        $this->restaurante->update(['plan_id' => $plan->id]);
    }

    public function test_admin_creates_a_user_and_the_temporary_password_comes_once_in_the_json(): void
    {
        $respuesta = $this->actingAs($this->admin)
            ->postJson(route('panel.configuracion.usuarios.store'), $this->nuevo())
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('usuario.email', 'luis@local.pe')
            ->assertJsonPath('usuario.rol', 'cajero');

        $temporal = $respuesta->json('password_temporal');
        $this->assertIsString($temporal);
        $this->assertSame(12, strlen($temporal));

        $usuario = User::where('email', 'luis@local.pe')->sole();
        $this->assertSame($this->restaurante->id, $usuario->restaurante_id);
        $this->assertTrue($usuario->debe_cambiar_password);
        $this->assertTrue(Hash::check($temporal, $usuario->password));

        // No queda en la sesión ni en la actividad, y la lista no la vuelve a mostrar.
        $this->assertStringNotContainsString($temporal, json_encode(session()->all()));
        $this->assertStringNotContainsString($temporal, ActividadLog::all()->toJson());
        $this->get(route('panel.configuracion.usuarios'))
            ->assertOk()
            ->assertSee('Luis Cajero')
            ->assertDontSee($temporal);
    }

    public function test_temporary_passwords_differ_and_avoid_ambiguous_characters(): void
    {
        $claves = collect(range(1, 50))->map(fn (): string => GestionarUsuarios::generarTemporal());

        $this->assertCount(50, $claves->unique());
        $claves->each(function (string $clave): void {
            $this->assertMatchesRegularExpression('/\A[A-HJ-NP-Za-km-z2-9]{12}\z/', $clave);
        });
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesNoPermitidos(): array
    {
        return ['superadmin' => ['superadmin'], 'cliente' => ['cliente'], 'inventado' => ['dueño']];
    }

    #[DataProvider('rolesNoPermitidos')]
    public function test_admin_cannot_create_or_promote_to_roles_outside_the_staff(string $rol): void
    {
        $cajero = User::factory()->cajero($this->restaurante)->create();

        $this->actingAs($this->admin)
            ->postJson(route('panel.configuracion.usuarios.store'), $this->nuevo(['rol' => $rol]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rol');

        $this->putJson(route('panel.configuracion.usuarios.update', $cajero->id), ['name' => $cajero->name, 'rol' => $rol])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rol');

        $this->assertDatabaseMissing('users', ['email' => 'luis@local.pe']);
        $this->assertSame('cajero', $cajero->fresh()->rol);
    }

    public function test_restaurant_cannot_be_injected_when_creating(): void
    {
        $ajeno = Restaurante::factory()->create();

        $this->actingAs($this->admin)
            ->postJson(route('panel.configuracion.usuarios.store'), $this->nuevo(['restaurante_id' => $ajeno->id, 'activo' => '0', 'debe_cambiar_password' => '0']))
            ->assertOk();

        $usuario = User::where('email', 'luis@local.pe')->sole();
        $this->assertSame($this->restaurante->id, $usuario->restaurante_id);
        $this->assertTrue($usuario->activo);
        $this->assertTrue($usuario->debe_cambiar_password);
    }

    public function test_email_must_be_unique_across_all_accounts(): void
    {
        User::factory()->admin(Restaurante::factory()->create())->create(['email' => 'luis@local.pe']);

        $this->actingAs($this->admin)
            ->postJson(route('panel.configuracion.usuarios.store'), $this->nuevo())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Ya existe una cuenta con ese correo. Usa otro.']);
    }

    public function test_admin_edits_name_phone_and_role_but_not_the_email(): void
    {
        $cajero = User::factory()->cajero($this->restaurante)->create(['email' => 'fijo@local.pe']);

        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.usuarios.update', $cajero->id), [
                'name' => 'Ana Cocina', 'telefono' => '912 345 678', 'rol' => 'cocina', 'email' => 'otro@local.pe',
            ])
            ->assertOk()
            ->assertJsonPath('usuario.rol_etiqueta', 'Cocina');

        $cajero->refresh();
        $this->assertSame('Ana Cocina', $cajero->name);
        $this->assertSame('912345678', $cajero->telefono);
        $this->assertSame('cocina', $cajero->rol);
        $this->assertSame('fijo@local.pe', $cajero->email);
    }

    public function test_admin_cannot_change_their_own_role_or_deactivate_themselves(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.usuarios.update', $this->admin->id), ['name' => 'Rosa', 'rol' => 'cajero'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rol' => 'No puedes cambiar tu propio rol. Pide a otro administrador que lo haga.']);

        $this->patchJson(route('panel.configuracion.usuarios.estado', $this->admin->id), ['activo' => '0'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['activo' => 'No puedes desactivar tu propia cuenta.']);

        $this->postJson(route('panel.configuracion.usuarios.password', $this->admin->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('usuario');

        $this->assertSame('admin', $this->admin->fresh()->rol);
        $this->assertTrue($this->admin->fresh()->activo);

        // Editar su propio nombre sí se puede.
        $this->putJson(route('panel.configuracion.usuarios.update', $this->admin->id), ['name' => 'Rosa Q.', 'rol' => 'admin'])->assertOk();
        $this->assertSame('Rosa Q.', $this->admin->fresh()->name);
    }

    public function test_the_restaurant_always_keeps_one_active_admin(): void
    {
        $otroAdmin = User::factory()->admin($this->restaurante)->create();
        $gestion = app(GestionarUsuarios::class);

        // Con dos admins, uno puede dejar de serlo.
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.usuarios.update', $otroAdmin->id), ['name' => $otroAdmin->name, 'rol' => 'cajero'])
            ->assertOk();

        // Ahora Rosa es la única: ni un cambio de rol ni la desactivación pueden dejar al local sin admin.
        try {
            $gestion->actualizar($otroAdmin->fresh(), $this->admin, ['name' => 'Rosa', 'telefono' => null, 'rol' => 'cajero']);
            $this->fail('Debió impedir quitar el último admin.');
        } catch (ValidationException $e) {
            $this->assertSame('El local debe tener al menos un administrador activo. Nombra a otro administrador antes.', $e->errors()['rol'][0]);
        }

        try {
            $gestion->cambiarActivo($otroAdmin->fresh(), $this->admin, false);
            $this->fail('Debió impedir desactivar el último admin.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('activo', $e->errors());
        }

        $this->assertSame('admin', $this->admin->fresh()->rol);
        $this->assertTrue($this->admin->fresh()->activo);

        // Un admin desactivado no cuenta como admin activo.
        $tercero = User::factory()->admin($this->restaurante)->inactivo()->create();
        $this->expectException(ValidationException::class);
        $gestion->actualizar($tercero, $this->admin, ['name' => 'Rosa', 'telefono' => null, 'rol' => 'mesero']);
    }

    public function test_deactivating_closes_all_sessions_and_clears_remember_token(): void
    {
        $cajero = User::factory()->cajero($this->restaurante)->create(['remember_token' => 'recordarme-123']);
        $this->sesionDe($cajero);
        $this->sesionDe($cajero);
        $sesionAdmin = $this->sesionDe($this->admin);

        $this->actingAs($this->admin)
            ->patchJson(route('panel.configuracion.usuarios.estado', $cajero->id), ['activo' => '0'])
            ->assertOk()
            ->assertJsonPath('usuario.activo', false);

        $this->assertFalse($cajero->fresh()->activo);
        $this->assertNull($cajero->fresh()->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $cajero->id)->count());
        $this->assertTrue(DB::table('sessions')->where('id', $sesionAdmin)->exists());
    }

    public function test_resetting_a_password_returns_a_new_temporary_one_and_closes_sessions(): void
    {
        $cajero = User::factory()->cajero($this->restaurante)->create(['remember_token' => 'recordarme-123']);
        $this->sesionDe($cajero);
        $hashAnterior = $cajero->password;

        $temporal = $this->actingAs($this->admin)
            ->postJson(route('panel.configuracion.usuarios.password', $cajero->id))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('password_temporal');

        $cajero->refresh();
        $this->assertNotSame($hashAnterior, $cajero->password);
        $this->assertTrue(Hash::check($temporal, $cajero->password));
        $this->assertTrue($cajero->debe_cambiar_password);
        $this->assertNull($cajero->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $cajero->id)->count());
        $this->assertStringNotContainsString($temporal, ActividadLog::all()->toJson());
    }

    public function test_plan_user_limit_blocks_creating_and_reactivating_but_counts_only_active_users(): void
    {
        $this->conPlan(3);
        User::factory()->cajero($this->restaurante)->create();
        $inactivo = User::factory()->cajero($this->restaurante)->inactivo()->create();

        // Activos: admin + cajero = 2 de 3 (el inactivo no cuenta).
        $this->actingAs($this->admin)
            ->postJson(route('panel.configuracion.usuarios.store'), $this->nuevo())
            ->assertOk();

        $this->postJson(route('panel.configuracion.usuarios.store'), $this->nuevo(['email' => 'cuarto@local.pe']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['usuario' => 'Tu plan Básico permite hasta 3 usuarios activos y ya tienes 3. Desactiva uno o mejora tu plan.']);

        $this->patchJson(route('panel.configuracion.usuarios.estado', $inactivo->id), ['activo' => '1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('usuario');

        $this->assertFalse($inactivo->fresh()->activo);
        $this->get(route('panel.configuracion.usuarios'))->assertSee('Llegaste al límite de 3 usuarios activos');

        // Al desactivar a alguien se libera el cupo y se puede reactivar al otro.
        $luis = User::where('email', 'luis@local.pe')->sole();
        $this->patchJson(route('panel.configuracion.usuarios.estado', $luis->id), ['activo' => '0'])->assertOk();
        $this->patchJson(route('panel.configuracion.usuarios.estado', $inactivo->id), ['activo' => '1'])->assertOk();
        $this->assertTrue($inactivo->fresh()->activo);
    }

    public function test_plan_without_limit_allows_any_number_of_users(): void
    {
        $this->conPlan(null);
        User::factory()->cajero($this->restaurante)->count(5)->create();

        $this->actingAs($this->admin)
            ->postJson(route('panel.configuracion.usuarios.store'), $this->nuevo())
            ->assertOk();
    }

    public function test_users_of_another_restaurant_respond_404(): void
    {
        $ajeno = User::factory()->cajero(Restaurante::factory()->create())->create(['name' => 'Ajeno']);
        $cliente = User::factory()->cliente()->create();

        $this->actingAs($this->admin);

        foreach ([$ajeno, $cliente] as $objetivo) {
            $this->putJson(route('panel.configuracion.usuarios.update', $objetivo->id), ['name' => 'Cambiado', 'rol' => 'cajero'])->assertNotFound();
            $this->patchJson(route('panel.configuracion.usuarios.estado', $objetivo->id), ['activo' => '0'])->assertNotFound();
            $this->postJson(route('panel.configuracion.usuarios.password', $objetivo->id))->assertNotFound();
        }

        $this->assertSame('Ajeno', $ajeno->fresh()->name);
        $this->assertTrue($ajeno->fresh()->activo);
    }

    public function test_a_cashier_cannot_manage_users(): void
    {
        $cajero = User::factory()->cajero($this->restaurante)->create();

        $this->actingAs($cajero)->get(route('panel.configuracion.usuarios'))->assertForbidden();
        $this->patchJson(route('panel.configuracion.usuarios.estado', $this->admin->id), ['activo' => '0'])->assertForbidden();
        $this->postJson(route('panel.configuracion.usuarios.password', $this->admin->id))->assertForbidden();

        $this->assertTrue($this->admin->fresh()->activo);
    }
}
