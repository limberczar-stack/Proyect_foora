<?php

namespace Tests\Feature\Carta;

use App\Models\CategoriaProducto;
use App\Models\Producto;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoriaTest extends TestCase
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

    public function test_admin_sees_the_categories_screen(): void
    {
        CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Bebidas']);

        $this->actingAs($this->admin)
            ->get(route('panel.carta.categorias.index'))
            ->assertOk()
            ->assertSee('Bebidas');
    }

    public function test_empty_state_when_there_are_no_categories(): void
    {
        $this->actingAs($this->admin)
            ->get(route('panel.carta.categorias.index'))
            ->assertOk()
            ->assertSee('Todavía no tienes categorías');
    }

    public function test_admin_creates_a_category_and_a_subcategory(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.carta.categorias.store'), ['nombre' => 'Bebidas', 'activa' => '1', 'con_fotos' => '0'])
            ->assertRedirect(route('panel.carta.categorias.index'))
            ->assertSessionHas('status');

        $bebidas = CategoriaProducto::where('nombre', 'Bebidas')->sole();
        $this->assertSame($this->restaurante->id, $bebidas->restaurante_id);
        $this->assertFalse($bebidas->con_fotos);

        $this->post(route('panel.carta.categorias.store'), ['nombre' => 'Frías', 'parent_id' => $bebidas->id, 'activa' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame($bebidas->id, CategoriaProducto::where('nombre', 'Frías')->sole()->parent_id);
    }

    public function test_only_one_level_of_subcategories_is_allowed(): void
    {
        $bebidas = CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Bebidas']);
        $frias = CategoriaProducto::factory()->hijaDe($bebidas)->create(['nombre' => 'Frías']);

        $this->actingAs($this->admin)
            ->post(route('panel.carta.categorias.store'), ['nombre' => 'Gaseosas', 'parent_id' => $frias->id])
            ->assertSessionHasErrors('parent_id');

        // Una categoría con subcategorías no puede volverse subcategoría.
        $postres = CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Postres']);
        $this->put(route('panel.carta.categorias.update', $bebidas), ['nombre' => 'Bebidas', 'parent_id' => $postres->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_admin_edits_and_toggles_a_category(): void
    {
        $categoria = CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Bebida', 'activa' => true]);

        $this->actingAs($this->admin)
            ->put(route('panel.carta.categorias.update', $categoria), ['nombre' => 'Bebidas', 'descripcion' => 'Frías y calientes', 'activa' => '1', 'con_fotos' => '0'])
            ->assertSessionHasNoErrors();

        $this->patch(route('panel.carta.categorias.estado', $categoria));

        $categoria->refresh();
        $this->assertSame('Bebidas', $categoria->nombre);
        $this->assertFalse($categoria->con_fotos);
        $this->assertFalse($categoria->activa);
    }

    public function test_admin_reorders_categories(): void
    {
        $primera = CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Entradas', 'orden' => 0]);
        $segunda = CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Fondos', 'orden' => 1]);

        $this->actingAs($this->admin)
            ->patch(route('panel.carta.categorias.mover', $segunda), ['direccion' => 'subir'])
            ->assertRedirect();

        $this->assertSame(0, $segunda->fresh()->orden);
        $this->assertSame(1, $primera->fresh()->orden);
    }

    public function test_empty_category_can_be_deleted(): void
    {
        $categoria = CategoriaProducto::factory()->for($this->restaurante)->create();

        $this->actingAs($this->admin)
            ->delete(route('panel.carta.categorias.destroy', $categoria))
            ->assertSessionHas('status');

        $this->assertModelMissing($categoria);
    }

    public function test_category_with_products_cannot_be_deleted(): void
    {
        $categoria = CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Parrillas']);
        Producto::factory()->for($categoria, 'categoria')->count(2)->create();

        $this->actingAs($this->admin)
            ->delete(route('panel.carta.categorias.destroy', $categoria))
            ->assertSessionHas('error', fn (string $mensaje): bool => str_contains($mensaje, 'tiene 2 productos'));

        $this->assertModelExists($categoria);
    }

    public function test_category_with_subcategories_cannot_be_deleted(): void
    {
        $bebidas = CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Bebidas']);
        CategoriaProducto::factory()->hijaDe($bebidas)->create(['nombre' => 'Frías']);

        $this->actingAs($this->admin)
            ->delete(route('panel.carta.categorias.destroy', $bebidas))
            ->assertSessionHas('error', fn (string $mensaje): bool => str_contains($mensaje, 'subcategoría'));

        $this->assertModelExists($bebidas);
    }
}
