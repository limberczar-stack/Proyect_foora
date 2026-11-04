<?php

namespace Tests\Feature\Carta;

use App\Models\CategoriaProducto;
use App\Models\Producto;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartaPermisosTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private CategoriaProducto $categoria;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->create();
        $this->categoria = CategoriaProducto::factory()->for($this->restaurante)->create();
        $this->producto = Producto::factory()->for($this->categoria, 'categoria')->create(['nombre' => 'Pollo entero', 'disponible' => true]);
    }

    public function test_cashier_sees_the_menu_without_management_actions(): void
    {
        $cajero = User::factory()->cajero($this->restaurante)->create();

        $this->actingAs($cajero)
            ->get(route('panel.carta.productos.index'))
            ->assertOk()
            ->assertSee('Pollo entero')
            ->assertSee('data-disponibilidad', false)
            ->assertDontSee('Nuevo producto')
            ->assertDontSee(route('panel.carta.productos.edit', $this->producto));

        $this->get(route('panel.carta.categorias.index'))->assertOk();
    }

    public function test_cashier_changes_availability(): void
    {
        $cajero = User::factory()->cajero($this->restaurante)->create();

        $this->actingAs($cajero)
            ->patchJson(route('panel.carta.productos.disponibilidad', $this->producto), ['disponible' => false])
            ->assertOk();

        $this->assertFalse($this->producto->fresh()->disponible);
    }

    public function test_cashier_cannot_create_edit_or_delete(): void
    {
        $this->actingAs(User::factory()->cajero($this->restaurante)->create());

        $this->get(route('panel.carta.productos.create'))->assertForbidden();
        $this->post(route('panel.carta.productos.store'), ['nombre' => 'X'])->assertForbidden();
        $this->get(route('panel.carta.productos.edit', $this->producto))->assertForbidden();
        $this->put(route('panel.carta.productos.update', $this->producto), ['nombre' => 'X'])->assertForbidden();
        $this->delete(route('panel.carta.productos.destroy', $this->producto))->assertForbidden();

        $this->post(route('panel.carta.categorias.store'), ['nombre' => 'X'])->assertForbidden();
        $this->put(route('panel.carta.categorias.update', $this->categoria), ['nombre' => 'X'])->assertForbidden();
        $this->patch(route('panel.carta.categorias.estado', $this->categoria))->assertForbidden();
        $this->patch(route('panel.carta.categorias.mover', $this->categoria), ['direccion' => 'bajar'])->assertForbidden();
        $this->delete(route('panel.carta.categorias.destroy', $this->categoria))->assertForbidden();

        $this->assertNotSoftDeleted($this->producto);
        $this->assertModelExists($this->categoria);
    }

    public function test_other_staff_roles_cannot_open_the_menu(): void
    {
        foreach (['mesero', 'cocina', 'repartidor'] as $rol) {
            $this->actingAs(User::factory()->personal($rol, $this->restaurante)->create())
                ->get(route('panel.carta.productos.index'))
                ->assertForbidden();
        }
    }

    public function test_superadmin_is_asked_to_select_a_restaurant(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->get(route('panel.carta.productos.index'))
            ->assertForbidden()
            ->assertSee('Selecciona un restaurante');
    }

    public function test_admin_sees_management_actions(): void
    {
        $this->actingAs(User::factory()->admin($this->restaurante)->create())
            ->get(route('panel.carta.productos.index'))
            ->assertOk()
            ->assertSee('Nuevo producto')
            ->assertSee(route('panel.carta.productos.edit', $this->producto));
    }
}
