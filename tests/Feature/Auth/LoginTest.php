<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_renders_for_guests(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Ingresa a tu panel')
            ->assertSee('name="_token"', false);
    }

    public function test_staff_member_logs_in_and_last_access_is_recorded(): void
    {
        $user = User::factory()->admin()->create(['ultimo_acceso_at' => null]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('panel.inicio'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->ultimo_acceso_at);
    }

    public function test_wrong_password_and_unknown_email_get_the_same_generic_message(): void
    {
        $user = User::factory()->admin()->create();

        $this->from(route('login'))
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrecta'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['auth' => LoginRequest::MENSAJE_CREDENCIALES]);

        $this->from(route('login'))
            ->post(route('login.store'), ['email' => 'nadie@foora.test', 'password' => 'password'])
            ->assertSessionHasErrors(['auth' => LoginRequest::MENSAJE_CREDENCIALES]);

        $this->assertGuest();
    }

    public function test_inactive_account_is_only_revealed_when_the_password_is_correct(): void
    {
        $user = User::factory()->admin()->inactivo()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrecta'])
            ->assertSessionHasErrors(['auth' => LoginRequest::MENSAJE_CREDENCIALES]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['auth' => LoginRequest::MENSAJE_INACTIVO]);

        $this->assertGuest();
    }

    public function test_customer_account_cannot_log_in_to_the_panel(): void
    {
        $user = User::factory()->cliente()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['auth' => LoginRequest::MENSAJE_SIN_PANEL]);

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_too_many_failed_attempts(): void
    {
        $user = User::factory()->admin()->create();

        for ($i = 0; $i < LoginRequest::MAX_INTENTOS; $i++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrecta']);
        }

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $response->assertSessionHasErrors('auth');
        $this->assertStringContainsString('Demasiados intentos', session('errors')->first('auth'));
        $this->assertGuest();
    }

    public function test_remember_me_sets_the_remember_cookie(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertCookie(auth()->guard('web')->getRecallerName());
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_user_can_log_out(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_authenticated_user_visiting_login_is_sent_to_the_panel(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('login'))
            ->assertRedirect(route('panel.inicio'));
    }
}
