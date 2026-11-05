<?php

namespace Tests\Feature\Carta;

use App\Models\CategoriaProducto;
use App\Models\GrupoOpcion;
use App\Models\Opcion;
use App\Models\Producto;
use App\Models\ProductoVariante;
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

    public function test_group_listing_only_shows_own_groups(): void
    {
        GrupoOpcion::factory()->for($this->adminA->restaurante)->create(['nombre' => 'Grupo A']);
        GrupoOpcion::factory()->for($this->productoB->restaurante)->create(['nombre' => 'Grupo B']);

        $this->actingAs($this->adminA)
            ->get(route('panel.carta.grupos.index'))
            ->assertSee('Grupo A')
            ->assertDontSee('Grupo B');

        $this->get(route('panel.carta.productos.create'))
            ->assertSee('Grupo A')
            ->assertDontSee('Grupo B');
    }

    public function test_groups_from_another_restaurant_are_not_found(): void
    {
        $grupoB = GrupoOpcion::factory()->for($this->productoB->restaurante)->create(['nombre' => 'Grupo B']);
        $opcionB = Opcion::factory()->for($grupoB, 'grupo')->create();

        $this->actingAs($this->adminA);

        $this->get(route('panel.carta.grupos.edit', $grupoB))->assertNotFound();
        $this->put(route('panel.carta.grupos.update', $grupoB), ['nombre' => 'Hackeado'])->assertNotFound();
        $this->delete(route('panel.carta.grupos.destroy', $grupoB))->assertNotFound();
        $this->patchJson(route('panel.carta.grupos.opciones.disponibilidad', [$grupoB, $opcionB]), ['disponible' => false])->assertNotFound();

        $this->assertSame('Grupo B', $grupoB->fresh()->nombre);
        $this->assertTrue($opcionB->fresh()->disponible);
    }

    public function test_nested_availability_requires_the_child_to_belong_to_its_parent(): void
    {
        $grupoA = GrupoOpcion::factory()->for($this->adminA->restaurante)->create();
        $otroGrupoA = GrupoOpcion::factory()->for($this->adminA->restaurante)->create();
        $opcionDeOtroGrupo = Opcion::factory()->for($otroGrupoA, 'grupo')->create();
        $opcionB = Opcion::factory()->for(GrupoOpcion::factory()->for($this->productoB->restaurante), 'grupo')->create();

        $productoA = Producto::withoutGlobalScopes()->where('restaurante_id', $this->adminA->restaurante_id)->firstOrFail();
        $varianteB = ProductoVariante::factory()->for($this->productoB)->create();

        $this->actingAs($this->adminA);

        $this->patchJson(route('panel.carta.grupos.opciones.disponibilidad', [$grupoA, $opcionDeOtroGrupo]), ['disponible' => false])->assertNotFound();
        $this->patchJson(route('panel.carta.grupos.opciones.disponibilidad', [$grupoA, $opcionB]), ['disponible' => false])->assertNotFound();
        $this->patchJson(route('panel.carta.productos.variantes.disponibilidad', [$productoA, $varianteB]), ['disponible' => false])->assertNotFound();
        $this->patchJson(route('panel.carta.productos.variantes.disponibilidad', [$this->productoB, $varianteB]), ['disponible' => false])->assertNotFound();

        $this->assertTrue($opcionDeOtroGrupo->fresh()->disponible);
        $this->assertTrue($opcionB->fresh()->disponible);
        $this->assertTrue($varianteB->fresh()->disponible);
    }

    public function test_a_group_from_another_restaurant_cannot_be_assigned_to_a_product(): void
    {
        $grupoB = GrupoOpcion::factory()->for($this->productoB->restaurante)->create();
        $categoriaA = CategoriaProducto::withoutGlobalScopes()->where('restaurante_id', $this->adminA->restaurante_id)->firstOrFail();

        $this->actingAs($this->adminA)
            ->post(route('panel.carta.productos.store'), [
                'nombre' => 'Intruso',
                'categoria_id' => $categoriaA->id,
                'tipo' => 'simple',
                'precio' => '10',
                'estacion' => 'cocina',
                'grupos' => [$grupoB->id],
            ])
            ->assertSessionHasErrors(['grupos.0' => 'Elige grupos de tu carta.']);

        $this->assertDatabaseCount('producto_grupos_opcion', 0);
        $this->assertDatabaseMissing('productos', ['nombre' => 'Intruso']);
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
