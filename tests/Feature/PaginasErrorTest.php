<?php

namespace Tests\Feature;

use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class PaginasErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_404_page_is_in_spanish_with_the_foora_design(): void
    {
        $this->get('/panel/no-existe-esta-ruta')
            ->assertNotFound()
            ->assertSee('FOORA')
            ->assertSee('No encontramos esta página')
            ->assertSee('Ir al inicio del panel')
            ->assertDontSee('Not Found');
    }

    public function test_404_of_another_restaurants_record_does_not_leak_model_details(): void
    {
        $admin = User::factory()->admin(Restaurante::factory()->create())->create();
        $ajeno = User::factory()->cajero(Restaurante::factory()->create())->create();

        $this->actingAs($admin)
            ->put(route('panel.configuracion.usuarios.update', $ajeno->id), ['name' => 'X', 'rol' => 'cajero'])
            ->assertNotFound()
            ->assertSee('No encontramos esta página')
            ->assertDontSee('App\\Models\\User', false);
    }

    public function test_403_shows_our_reason_or_a_clear_generic_one(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->get(route('panel.configuracion.general'))
            ->assertForbidden()
            ->assertSee('No tienes acceso a esta sección')
            ->assertSee('Selecciona un restaurante.');

        $this->actingAs(User::factory()->cajero(Restaurante::factory()->create())->create())
            ->get(route('panel.configuracion.general'))
            ->assertForbidden()
            ->assertSee('Tu cuenta no tiene permiso para ver esta sección.')
            ->assertDontSee('This action is unauthorized.');
    }

    public function test_500_page_is_in_spanish_and_hides_the_error(): void
    {
        config(['app.debug' => false]);
        Route::get('/_pruebas/error-500', fn () => throw new RuntimeException('detalle interno secreto'));

        $this->get('/_pruebas/error-500')
            ->assertInternalServerError()
            ->assertSee('Algo falló de nuestro lado')
            ->assertSee('Volver a intentar')
            ->assertDontSee('detalle interno secreto');
    }

    public function test_public_errors_point_to_the_home_page_not_the_panel(): void
    {
        $this->get('/no-existe/esto/tampoco')
            ->assertNotFound()
            ->assertSee('Ir a la portada')
            ->assertDontSee('Ir al inicio del panel');
    }
}
