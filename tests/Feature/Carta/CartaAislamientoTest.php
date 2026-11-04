<?php

namespace Tests\Feature\Carta;

use App\Models\CategoriaProducto;
use App\Models\Producto;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartaAislamientoTest extends TestCase
{
    use RefreshDatabase;

    private User $adminA;

    private Producto $productoB;

    private CategoriaProducto $categoriaB;

    protected function setUp(): void
    {
        parent::setUp();

        $restauranteA = Restaurante::factory()->create();
        $restauranteB = Restaurante::factory()->create();

        $this->adminA = User::factory()->admin($restauranteA)->create();

        $categoriaA = CategoriaProducto::factory()->for($restauranteA)->create(['nombre' => 'Pollos A']);
        Producto::factory()->for($categoriaA, 'categoria')->create(['nombre' => 'Pollo del local A']);

        $this->categoriaB = CategoriaProducto::factory()->for($restauranteB)->create(['nombre' => 'Ceviches B']);
        $this->productoB = Producto::factory()->for($this->categoriaB, 'categoria')->create(['nombre' => 'Ceviche del local B', 'disponible' => true]);
    }

    public function test_menu_listing_only_shows_own_products_and_categories(): void
    {
        $this->actingAs($this->adminA)
            ->get(route('panel.carta.productos.index'))
            ->assertOk()
            ->assertSee('Pollo del local A')
            ->assertDontSee('Ceviche del local B')
            ->assertDontSee('Ceviches B');

        $this->get(route('panel.carta.categorias.index'))
            ->assertSee('Pollos A')
            ->assertDontSee('Ceviches B');
    }

    public function test_products_from_another_restaurant_are_not_found(): void
    {
        $this->actingAs($this->adminA);

        $this->get(route('panel.carta.productos.edit', $this->productoB))->assertNotFound();
        $this->put(route('panel.carta.productos.update', $this->productoB), ['nombre' => 'Hackeado'])->assertNotFound();
        $this->delete(route('panel.carta.productos.destroy', $this->productoB))->assertNotFound();
        $this->patchJson(route('panel.carta.productos.disponibilidad', $this->productoB), ['disponible' => false])->assertNotFound();

        $this->productoB->refresh();
        $this->assertSame('Ceviche del local B', $this->productoB->nombre);
        $this->assertTrue($this->productoB->disponible);
        $this->assertNotSoftDeleted($this->productoB);
    }

    public function test_categories_from_another_restaurant_are_not_found_or_usable(): void
    {
        $this->actingAs($this->adminA);

        $this->put(route('panel.carta.categorias.update', $this->categoriaB), ['nombre' => 'Hackeada'])->assertNotFound();
        $this->delete(route('panel.carta.categorias.destroy', $this->categoriaB))->assertNotFound();

        $this->post(route('panel.carta.categorias.store'), ['nombre' => 'Intrusa', 'parent_id' => $this->categoriaB->id])
            ->assertSessionHasErrors('parent_id');

        $this->assertSame('Ceviches B', $this->categoriaB->fresh()->nombre);
    }
}
