<?php

namespace Tests\Feature\Panel;

use App\Models\Alerta;
use App\Models\Pedido;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MenuLateralTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->create();
        $this->admin = User::factory()->admin($this->restaurante)->create();
    }

    private function registrarRuta(string $nombre): void
    {
        Route::middleware('web')->get('/_pruebas/'.str_replace('.', '-', $nombre), fn (): string => 'ok')->name($nombre);
        Route::getRoutes()->refreshNameLookups();
    }

    private function alertaPendiente(?Restaurante $restaurante = null): void
    {
        Alerta::create([
            'restaurante_id' => ($restaurante ?? $this->restaurante)->id,
            'tipo' => 'pedido_nuevo',
            'prioridad' => 'media',
            'titulo' => 'Nuevo pedido',
        ]);
    }

    /**
     * Texto del menú lateral, para no confundirlo con el contenido de la página.
     */
    private function menu(TestResponse $respuesta): string
    {
        preg_match('/<aside.*?<\/aside>/s', $respuesta->getContent(), $coincidencias);

        return $coincidencias[0] ?? '';
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pantallasPorRol(): array
    {
        return [
            'admin' => ['admin', 'panel.inicio'],
            'cajero' => ['cajero', 'panel.inicio'],
            'cocina' => ['cocina', 'panel.pedidos.index'],
            'mesero' => ['mesero', 'panel.inicio'],
            'repartidor' => ['repartidor', 'panel.inicio'],
        ];
    }

    #[DataProvider('pantallasPorRol')]
    public function test_no_link_points_to_a_hash_for_any_role(string $rol, string $ruta): void
    {
        $usuario = User::factory()->personal($rol, $this->restaurante)->create();

        $contenido = $this->actingAs($usuario)->get(route($ruta))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/href\s*=\s*["\']#["\']/', $contenido);
    }

    public function test_superadmin_menu_has_no_hash_links_and_only_its_section(): void
    {
        $respuesta = $this->actingAs(User::factory()->superadmin()->create())
            ->get(route('panel.superadmin.resumen'))
            ->assertOk();

        $menu = $this->menu($respuesta);

        $this->assertStringNotContainsString('href="#"', $respuesta->getContent());
        $this->assertStringContainsString('Restaurantes', $menu);
        $this->assertStringNotContainsString('>Pedidos<', $menu);
    }

    public function test_admin_sees_only_the_modules_that_exist(): void
    {
        $menu = $this->menu($this->actingAs($this->admin)->get(route('panel.inicio')));

        foreach (['Inicio', 'Pedidos', 'Carta', 'Configuración'] as $existente) {
            $this->assertStringContainsString($existente, $menu);
        }

        foreach (['Alertas', 'Reservas', 'Mesas', 'Promociones', 'Actividad', 'Ayuda'] as $oculto) {
            $this->assertStringNotContainsString($oculto, $menu);
        }
    }

    public function test_menu_only_offers_what_each_role_can_open(): void
    {
        $cajero = User::factory()->cajero($this->restaurante)->create();
        $menuCajero = $this->menu($this->actingAs($cajero)->get(route('panel.inicio')));
        $this->assertStringContainsString('Pedidos', $menuCajero);
        $this->assertStringContainsString('Carta', $menuCajero);
        $this->assertStringNotContainsString('Configuración', $menuCajero);

        $cocina = User::factory()->personal('cocina', $this->restaurante)->create();
        $menuCocina = $this->menu($this->actingAs($cocina)->get(route('panel.pedidos.index')));
        $this->assertStringContainsString('Pedidos', $menuCocina);
        $this->assertStringNotContainsString('Carta', $menuCocina);

        $mesero = User::factory()->personal('mesero', $this->restaurante)->create();
        $menuMesero = $this->menu($this->actingAs($mesero)->get(route('panel.inicio')));
        $this->assertStringContainsString('Inicio', $menuMesero);
        $this->assertStringNotContainsString('Pedidos', $menuMesero);
        $this->assertStringNotContainsString('Carta', $menuMesero);
    }

    public function test_kitchen_badge_does_not_count_orders_it_cannot_open(): void
    {
        Pedido::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'nuevo']);

        $cocina = User::factory()->personal('cocina', $this->restaurante)->create();

        $this->assertStringContainsString('<span data-nav-badge-numero>0</span>', $this->menu($this->actingAs($cocina)->get(route('panel.pedidos.index'))));
        $this->assertStringContainsString('<span data-nav-badge-numero>1</span>', $this->menu($this->actingAs($this->admin)->get(route('panel.inicio'))));
    }

    public function test_activating_a_module_without_a_route_changes_nothing(): void
    {
        config(['foora.modulos.mesas' => true, 'foora.modulos.alertas' => true]);

        $respuesta = $this->actingAs($this->admin)->get(route('panel.inicio'))->assertOk();
        $menu = $this->menu($respuesta);

        $this->assertStringNotContainsString('Mesas', $menu);
        $this->assertStringNotContainsString('Alertas', $menu);
        $respuesta->assertDontSee('aria-label="Alertas:', false);
    }

    public function test_a_hidden_module_stays_hidden_even_if_its_route_exists(): void
    {
        $this->registrarRuta('panel.mesas.index');

        $this->assertStringNotContainsString('Mesas', $this->menu($this->actingAs($this->admin)->get(route('panel.inicio'))));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function modulos(): array
    {
        return [
            'reservas' => ['reservas', 'panel.reservas.index', 'Reservas'],
            'mesas' => ['mesas', 'panel.mesas.index', 'Mesas'],
            'promociones' => ['promociones', 'panel.promociones.index', 'Promociones'],
            'actividad' => ['actividad', 'panel.actividad.index', 'Actividad'],
            'ayuda' => ['ayuda', 'panel.ayuda', 'Ayuda'],
        ];
    }

    #[DataProvider('modulos')]
    public function test_an_active_module_with_a_route_appears_with_its_real_link(string $modulo, string $ruta, string $etiqueta): void
    {
        $this->registrarRuta($ruta);
        config(["foora.modulos.{$modulo}" => true]);

        $menu = $this->menu($this->actingAs($this->admin)->get(route('panel.inicio')));

        $this->assertStringContainsString($etiqueta, $menu);
        $this->assertStringContainsString(route($ruta), $menu);
    }

    public function test_alert_badge_and_bell_are_hidden_while_the_module_is_hidden(): void
    {
        $this->alertaPendiente();

        $this->actingAs($this->admin)->get(route('panel.inicio'))
            ->assertDontSee('alertas sin resolver')
            ->assertDontSee('aria-label="Alertas:', false);
    }

    public function test_alert_badge_shows_the_real_unresolved_count_of_this_restaurant_when_active(): void
    {
        $this->registrarRuta('panel.alertas.index');
        config(['foora.modulos.alertas' => true]);

        $this->alertaPendiente();
        $this->alertaPendiente();
        $this->alertaPendiente(Restaurante::factory()->create());
        Alerta::create(['restaurante_id' => $this->restaurante->id, 'tipo' => 'pedido_nuevo', 'prioridad' => 'media', 'titulo' => 'Resuelta', 'resuelta_at' => now()]);

        $respuesta = $this->actingAs($this->admin)->get(route('panel.inicio'));

        $this->assertStringContainsString('<span data-nav-badge-numero>2</span><span class="sr-only"> alertas sin resolver</span>', $this->menu($respuesta));
        $respuesta
            ->assertSee('aria-label="Alertas: 2 sin resolver"', false)
            ->assertSee(route('panel.alertas.index'));
    }

    public function test_alert_module_is_not_offered_to_roles_that_do_not_use_it(): void
    {
        $this->registrarRuta('panel.alertas.index');
        config(['foora.modulos.alertas' => true]);

        $mesero = User::factory()->personal('mesero', $this->restaurante)->create();

        $this->actingAs($mesero)->get(route('panel.inicio'))->assertDontSee('aria-label="Alertas:', false);
    }

    public function test_forbidden_page_lets_a_signed_in_user_log_out(): void
    {
        $cliente = User::factory()->cliente()->create();

        $this->actingAs($cliente)
            ->get(route('panel.inicio'))
            ->assertForbidden()
            ->assertSee('Cerrar sesión')
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('name="_token"', false);
    }
}
