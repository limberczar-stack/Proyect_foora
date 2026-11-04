<?php

namespace Tests\Feature;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'panel', 'rol:admin'])
            ->get('/_pruebas/solo-admin', fn (): string => 'ok');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('panel.inicio'))->assertRedirect(route('login'));
    }

    public function test_staff_member_sees_their_real_restaurant_and_name(): void
    {
        $restaurante = Restaurante::factory()->create(['nombre' => 'Pollería El Buen Sabor', 'estado_local' => 'abierto']);
        $user = User::factory()->admin($restaurante)->create(['name' => 'Rosa Quispe']);

        $this->actingAs($user)
            ->get(route('panel.inicio'))
            ->assertOk()
            ->assertSee('Pollería El Buen Sabor')
            ->assertSee('Rosa Quispe')
            ->assertSee('Administrador')
            ->assertSee('disponible en Configuración');
    }

    public function test_customer_is_forbidden(): void
    {
        $this->actingAs(User::factory()->cliente()->create())
            ->get(route('panel.inicio'))
            ->assertForbidden();
    }

    public function test_staff_member_without_restaurant_is_forbidden(): void
    {
        $user = User::factory()->create(['rol' => 'cajero', 'restaurante_id' => null]);

        $this->actingAs($user)->get(route('panel.inicio'))->assertForbidden();
    }

    public function test_staff_member_of_an_inactive_restaurant_is_forbidden(): void
    {
        $user = User::factory()->admin(Restaurante::factory()->inactivo()->create())->create();

        $this->actingAs($user)->get(route('panel.inicio'))->assertForbidden();
    }

    public function test_deactivated_user_with_an_open_session_is_logged_out(): void
    {
        $user = User::factory()->admin()->inactivo()->create();

        $this->actingAs($user)
            ->get(route('panel.inicio'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertGuest();
    }

    public function test_superadmin_enters_without_a_restaurant(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->get(route('panel.inicio'))
            ->assertOk()
            ->assertSee('Superadmin · todos los locales');
    }

    public function test_role_middleware_allows_listed_roles(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/_pruebas/solo-admin')
            ->assertOk()
            ->assertSee('ok');
    }

    public function test_role_middleware_rejects_other_roles(): void
    {
        $this->actingAs(User::factory()->cajero()->create())
            ->get('/_pruebas/solo-admin')
            ->assertForbidden();
    }

    public function test_role_middleware_always_lets_the_superadmin_through(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/_pruebas/solo-admin')
            ->assertOk();
    }
}
