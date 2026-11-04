<?php

namespace Tests\Feature\Carta;

use App\Models\CategoriaProducto;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductoTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    private CategoriaProducto $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->restaurante = Restaurante::factory()->create();
        $this->admin = User::factory()->admin($this->restaurante)->create();
        $this->categoria = CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Pollos a la brasa']);
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        return array_merge([
            'nombre' => '1/2 pollo a la brasa',
            'categoria_id' => $this->categoria->id,
            'tipo' => 'simple',
            'descripcion' => 'Con papas y ensalada.',
            'precio' => '32.00',
            'estacion' => 'cocina',
            'disponible' => '1',
            'destacado' => '0',
            'etiquetas' => ['nuevo'],
        ], $cambios);
    }

    public function test_listing_shows_products_and_the_empty_state(): void
    {
        $this->actingAs($this->admin)
            ->get(route('panel.carta.productos.index'))
            ->assertOk()
            ->assertSee('Tu carta está vacía');

        Producto::factory()->for($this->categoria, 'categoria')->create(['nombre' => 'Pollo entero']);

        $this->get(route('panel.carta.productos.index'))
            ->assertOk()
            ->assertSee('Pollo entero')
            ->assertDontSee('Tu carta está vacía');
    }

    public function test_listing_filters_by_category_including_subcategories_and_by_name(): void
    {
        $bebidas = CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Bebidas']);
        $frias = CategoriaProducto::factory()->hijaDe($bebidas)->create(['nombre' => 'Frías']);
        Producto::factory()->for($frias, 'categoria')->create(['nombre' => 'Chicha morada']);
        Producto::factory()->for($this->categoria, 'categoria')->create(['nombre' => 'Pollo entero']);

        $this->actingAs($this->admin)
            ->get(route('panel.carta.productos.index', ['categoria' => $bebidas->id]))
            ->assertSee('Chicha morada')
            ->assertDontSee('Pollo entero');

        $this->get(route('panel.carta.productos.index', ['q' => 'pollo']))
            ->assertSee('Pollo entero')
            ->assertDontSee('Chicha morada');
    }

    public function test_create_and_edit_forms_render(): void
    {
        $producto = Producto::factory()->for($this->categoria, 'categoria')->create(['nombre' => 'Chaufa de pollo', 'tipo' => 'variantes']);
        $producto->variantes()->create(['nombre' => 'Taza', 'precio' => 11]);
        $producto->variantes()->create(['nombre' => 'Familiar', 'precio' => 44]);

        $this->actingAs($this->admin)
            ->get(route('panel.carta.productos.create'))
            ->assertOk()
            ->assertSee('Nuevo producto')
            ->assertSee('Pollos a la brasa');

        $this->get(route('panel.carta.productos.edit', $producto))
            ->assertOk()
            ->assertSee('value="Taza"', false)
            ->assertSee('value="44.00"', false);
    }

    public function test_admin_creates_a_simple_product_with_an_image(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.carta.productos.store'), $this->datos([
                'imagen' => UploadedFile::fake()->image('pollo.jpg', 800, 600)->size(500),
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('panel.carta.productos.index'));

        $producto = Producto::sole();
        $this->assertSame($this->restaurante->id, $producto->restaurante_id);
        $this->assertSame('32.00', $producto->precio);
        $this->assertSame(['nuevo'], $producto->etiquetas);
        $this->assertStringStartsWith('productos/', $producto->imagen);
        $this->assertStringNotContainsString('pollo', $producto->imagen, 'La imagen se guarda con nombre aleatorio.');
        Storage::disk('public')->assertExists($producto->imagen);
    }

    public function test_product_with_variants_needs_at_least_two_and_takes_the_lowest_price(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.carta.productos.store'), $this->datos([
                'tipo' => 'variantes',
                'precio' => '',
                'variantes' => [['nombre' => 'Taza', 'precio' => '11.00', 'disponible' => '1']],
            ]))
            ->assertSessionHasErrors('variantes');

        $this->post(route('panel.carta.productos.store'), $this->datos([
            'nombre' => 'Chaufa de pollo',
            'tipo' => 'variantes',
            'precio' => '',
            'variantes' => [
                ['nombre' => 'Personal', 'precio' => '22.00', 'disponible' => '1'],
                ['nombre' => 'Taza', 'precio' => '11,00', 'disponible' => '1'],
                ['nombre' => 'Familiar', 'precio' => '44.00', 'disponible' => '0'],
            ],
        ]))->assertSessionHasNoErrors();

        $producto = Producto::where('nombre', 'Chaufa de pollo')->sole();
        $this->assertSame('variantes', $producto->tipo);
        $this->assertSame('11.00', $producto->precio);
        $this->assertSame(['Personal', 'Taza', 'Familiar'], $producto->variantes->pluck('nombre')->all());
        $this->assertFalse($producto->variantes->firstWhere('nombre', 'Familiar')->disponible);
    }

    public function test_editing_syncs_variants_by_id(): void
    {
        $producto = Producto::factory()->for($this->categoria, 'categoria')->create(['tipo' => 'variantes', 'precio' => 11]);
        $taza = $producto->variantes()->create(['nombre' => 'Taza', 'precio' => 11, 'orden' => 0]);
        $personal = $producto->variantes()->create(['nombre' => 'Personal', 'precio' => 22, 'orden' => 1]);
        $familiar = $producto->variantes()->create(['nombre' => 'Familiar', 'precio' => 44, 'orden' => 2]);

        $this->actingAs($this->admin)
            ->put(route('panel.carta.productos.update', $producto), $this->datos([
                'nombre' => $producto->nombre,
                'tipo' => 'variantes',
                'variantes' => [
                    ['id' => $personal->id, 'nombre' => 'Personal', 'precio' => '23.00', 'disponible' => '1'],
                    ['id' => $taza->id, 'nombre' => 'Taza', 'precio' => '12.00', 'disponible' => '1'],
                    ['id' => null, 'nombre' => 'Mediano', 'precio' => '32.00', 'disponible' => '1'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('23.00', $personal->fresh()->precio, 'La variante existente se actualiza con el mismo id.');
        $this->assertSame(0, $personal->fresh()->orden);
        $this->assertSame('12.00', $taza->fresh()->precio);
        $this->assertModelMissing($familiar);
        $this->assertTrue(ProductoVariante::where('producto_id', $producto->id)->where('nombre', 'Mediano')->exists());
        $this->assertSame(3, ProductoVariante::where('producto_id', $producto->id)->count());
        $this->assertSame('12.00', $producto->fresh()->precio);
    }

    public function test_variant_ids_from_another_product_are_not_hijacked(): void
    {
        $otro = Producto::factory()->for($this->categoria, 'categoria')->create(['tipo' => 'variantes']);
        $ajena = $otro->variantes()->create(['nombre' => 'Vaso', 'precio' => 5]);
        $producto = Producto::factory()->for($this->categoria, 'categoria')->create();

        $this->actingAs($this->admin)
            ->put(route('panel.carta.productos.update', $producto), $this->datos([
                'nombre' => $producto->nombre,
                'tipo' => 'variantes',
                'variantes' => [
                    ['id' => $ajena->id, 'nombre' => 'Vaso', 'precio' => '6.00', 'disponible' => '1'],
                    ['id' => null, 'nombre' => 'Jarra', 'precio' => '16.00', 'disponible' => '1'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('5.00', $ajena->fresh()->precio);
        $this->assertSame($otro->id, $ajena->fresh()->producto_id);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function preciosInvalidos(): array
    {
        return [
            'cero' => ['0'],
            'negativo' => ['-5'],
            'sobre el máximo' => ['10000'],
            'tres decimales' => ['12.505'],
            'texto' => ['doce'],
        ];
    }

    #[DataProvider('preciosInvalidos')]
    public function test_price_must_be_greater_than_zero_and_at_most_9999_99(string $precio): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.carta.productos.store'), $this->datos(['precio' => $precio]))
            ->assertSessionHasErrors('precio');

        $this->post(route('panel.carta.productos.store'), $this->datos([
            'tipo' => 'variantes',
            'variantes' => [
                ['nombre' => 'Personal', 'precio' => $precio, 'disponible' => '1'],
                ['nombre' => 'Familiar', 'precio' => '40.00', 'disponible' => '1'],
            ],
        ]))->assertSessionHasErrors('variantes.0.precio');

        $this->assertSame(0, Producto::count());
    }

    public function test_maximum_price_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.carta.productos.store'), $this->datos(['precio' => '9999.99']))
            ->assertSessionHasNoErrors();
    }

    public function test_image_must_be_jpg_png_or_webp_up_to_2_mb(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('panel.carta.productos.store'), $this->datos(['imagen' => UploadedFile::fake()->image('foto.gif')]))
            ->assertSessionHasErrors(['imagen' => 'La imagen debe ser JPG, PNG o WEBP.']);

        $this->post(route('panel.carta.productos.store'), $this->datos(['imagen' => UploadedFile::fake()->create('carta.pdf', 100, 'application/pdf')]))
            ->assertSessionHasErrors('imagen');

        $this->post(route('panel.carta.productos.store'), $this->datos(['imagen' => UploadedFile::fake()->image('grande.jpg')->size(2049)]))
            ->assertSessionHasErrors(['imagen' => 'La imagen pesa más de 2 MB.']);

        $this->post(route('panel.carta.productos.store'), $this->datos(['imagen' => UploadedFile::fake()->image('ok.webp')->size(2048)]))
            ->assertSessionHasNoErrors();
    }

    public function test_replacing_or_removing_the_image_deletes_the_previous_file(): void
    {
        Storage::disk('public')->put('productos/anterior.jpg', 'x');
        $producto = Producto::factory()->for($this->categoria, 'categoria')->create(['imagen' => 'productos/anterior.jpg']);

        $this->actingAs($this->admin)
            ->put(route('panel.carta.productos.update', $producto), $this->datos([
                'nombre' => $producto->nombre,
                'imagen' => UploadedFile::fake()->image('nueva.png'),
            ]))
            ->assertSessionHasNoErrors();

        $nueva = $producto->fresh()->imagen;
        Storage::disk('public')->assertMissing('productos/anterior.jpg');
        Storage::disk('public')->assertExists($nueva);

        $this->put(route('panel.carta.productos.update', $producto), $this->datos(['nombre' => $producto->nombre, 'quitar_imagen' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertNull($producto->fresh()->imagen);
        Storage::disk('public')->assertMissing($nueva);
    }

    public function test_category_from_another_restaurant_is_rejected(): void
    {
        $ajena = CategoriaProducto::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('panel.carta.productos.store'), $this->datos(['categoria_id' => $ajena->id]))
            ->assertSessionHasErrors(['categoria_id' => 'Elige una categoría de tu carta.']);
    }

    public function test_admin_soft_deletes_a_product(): void
    {
        $producto = Producto::factory()->for($this->categoria, 'categoria')->create();

        $this->actingAs($this->admin)
            ->delete(route('panel.carta.productos.destroy', $producto))
            ->assertSessionHas('status');

        $this->assertSoftDeleted($producto);
    }

    public function test_availability_toggle_saves_instantly(): void
    {
        $producto = Producto::factory()->for($this->categoria, 'categoria')->create(['disponible' => true]);

        $this->actingAs($this->admin)
            ->patchJson(route('panel.carta.productos.disponibilidad', $producto), ['disponible' => false])
            ->assertOk()
            ->assertJson(['disponible' => false]);

        $this->assertFalse($producto->fresh()->disponible);
    }

    public function test_incomplete_count_ignores_missing_photos_in_categories_without_photos(): void
    {
        $bebidas = CategoriaProducto::factory()->for($this->restaurante)->sinFotos()->create(['nombre' => 'Bebidas']);

        Producto::factory()->for($this->categoria, 'categoria')->create();
        Producto::factory()->for($this->categoria, 'categoria')->sinImagen()->create(['nombre' => 'Sin foto']);
        Producto::factory()->for($this->categoria, 'categoria')->sinDescripcion()->create(['nombre' => 'Sin descripción']);
        Producto::factory()->for($bebidas, 'categoria')->sinImagen()->create(['nombre' => 'Limonada']);
        Producto::factory()->for($bebidas, 'categoria')->sinImagen()->sinDescripcion()->create(['nombre' => 'Inca Kola']);

        $this->actingAs($this->admin);

        $this->assertEqualsCanonicalizing(['Sin foto', 'Sin descripción', 'Inca Kola'], Producto::incompletos()->pluck('nombre')->all());

        $this->get(route('panel.carta.productos.index', ['incompletos' => 1]))
            ->assertSee('Carta incompleta: 3 productos')
            ->assertSee('Sin foto')
            ->assertDontSee('Limonada');
    }
}
