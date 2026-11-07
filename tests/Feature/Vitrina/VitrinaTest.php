<?php

namespace Tests\Feature\Vitrina;

use App\Models\CategoriaProducto;
use App\Models\GrupoOpcion;
use App\Models\HorarioAtencion;
use App\Models\MetodoPago;
use App\Models\Opcion;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\Restaurante;
use App\Models\User;
use App\Rules\SlugDisponible;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class VitrinaTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private CategoriaProducto $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->create([
            'nombre' => 'Pollería Central',
            'slug' => 'polleria-central',
            'descripcion' => 'Pollo a la leña.',
            'direccion' => 'Jr. Dos de Mayo 1020',
            'costo_delivery' => 5,
            'pedido_minimo' => 20,
            'tiempo_prep_min' => 35,
        ]);
        $this->categoria = CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Pollos']);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function producto(array $atributos = []): Producto
    {
        return Producto::factory()->for($this->categoria, 'categoria')->create($atributos);
    }

    private function url(?Restaurante $restaurante = null): string
    {
        return route('vitrina.show', ($restaurante ?? $this->restaurante)->slug);
    }

    public function test_public_page_shows_the_restaurant_without_login(): void
    {
        $this->assertGuest();

        $metodo = MetodoPago::create(['codigo' => 'yape', 'nombre' => 'Yape']);
        $this->restaurante->metodosPago()->attach($metodo, ['activo' => true]);
        $apagado = MetodoPago::create(['codigo' => 'plin', 'nombre' => 'Plin']);
        $this->restaurante->metodosPago()->attach($apagado, ['activo' => false]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Pollería Central')
            ->assertSee('Pollo a la leña.')
            ->assertSee('Jr. Dos de Mayo 1020, Huánuco')
            ->assertSee('5.00')
            ->assertSee('20.00')
            ->assertSee('35 min')
            ->assertSee('Yape')
            ->assertDontSee('Plin')
            ->assertSee('Abierto');
    }

    public function test_public_page_sets_no_cookies(): void
    {
        $respuesta = $this->get($this->url())->assertOk();

        $this->assertCount(0, $respuesta->headers->getCookies());
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_missing_inactive_hidden_or_deleted_restaurants_are_not_found(): void
    {
        $inactivo = Restaurante::factory()->inactivo()->create();
        $oculto = Restaurante::factory()->create(['visible_en_app' => false]);
        $eliminado = Restaurante::factory()->create();
        $eliminado->delete();

        $this->get('/no-existe')->assertNotFound();
        $this->get($this->url($inactivo))->assertNotFound();
        $this->get($this->url($oculto))->assertNotFound();
        $this->get($this->url($eliminado))->assertNotFound();
    }

    public function test_slug_with_invalid_format_does_not_match_the_route(): void
    {
        Restaurante::factory()->create(['slug' => 'Mayusculas']);

        $this->get('/Mayusculas')->assertNotFound();
        $this->get('/con--doble')->assertNotFound();
    }

    public function test_system_routes_are_not_captured_by_the_slug_route(): void
    {
        foreach (['login', 'logout', 'panel', 'up', 'carrito'] as $slug) {
            Restaurante::factory()->create(['slug' => $slug, 'nombre' => "Impostor {$slug}"]);
        }

        $this->get('/login')->assertOk()->assertDontSee('Impostor')->assertSee('password');
        $this->get('/panel')->assertRedirect(route('login'));
        $this->get('/up')->assertOk();
        $this->get('/logout')->assertMethodNotAllowed();
        $this->get('/carrito')->assertNotFound();

        $this->actingAs(User::factory()->admin($this->restaurante)->create());
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_reserved_slug_cannot_reach_the_cart_endpoint(): void
    {
        Restaurante::factory()->create(['slug' => 'panel']);

        $this->postJson('/panel/carrito/validar', ['lineas' => []])->assertNotFound();
    }

    public function test_demo_restaurants_are_public_with_schedules_and_usable_slugs(): void
    {
        $this->seed(DemoSeeder::class);

        foreach (['el-buen-sabor', 'la-bahia', 'chifa-dragon-dorado'] as $slug) {
            $restaurante = Restaurante::where('slug', $slug)->firstOrFail();

            $this->assertFalse(Validator::make(['slug' => $slug], ['slug' => [new SlugDisponible]])->fails());
            $this->assertTrue($restaurante->visible_en_app);
            $this->assertSame(7, HorarioAtencion::where('restaurante_id', $restaurante->id)->count());
            $this->assertNotSame(0, $restaurante->metodosPago()->count());

            $this->get($this->url($restaurante))->assertOk();
        }
    }

    public function test_menu_shows_products_with_their_price_formats(): void
    {
        $this->producto(['nombre' => 'Anticuchos', 'precio' => 18.50, 'etiquetas' => ['picante']]);
        $variantes = $this->producto(['nombre' => 'Chaufa', 'tipo' => 'variantes', 'precio' => 11]);
        ProductoVariante::factory()->for($variantes)->create(['nombre' => 'Taza', 'precio' => 11]);
        $this->producto(['nombre' => 'Banquete', 'tipo' => 'combo', 'personas' => 5, 'precio' => 129]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Anticuchos')
            ->assertSee('18.50')
            ->assertSee('Picante')
            ->assertSee('desde')
            ->assertSee('Para 5 personas');
    }

    public function test_product_images_are_lazy_loaded(): void
    {
        $this->producto(['imagen' => 'productos/uno.jpg']);

        $this->get($this->url())->assertSee('loading="lazy"', false);
    }

    public function test_sold_out_product_is_marked_and_cannot_be_opened(): void
    {
        $producto = $this->producto(['nombre' => 'Parrilla', 'disponible' => false]);

        $this->get($this->url())
            ->assertSee('Parrilla')
            ->assertSee('Agotado')
            ->assertSee('data-producto="'.$producto->id.'"', false)
            ->assertSee('disabled', false);
    }

    public function test_inactive_categories_and_their_children_do_not_appear(): void
    {
        $inactiva = CategoriaProducto::factory()->for($this->restaurante)->create(['nombre' => 'Oculta', 'activa' => false]);
        Producto::factory()->for($inactiva, 'categoria')->create(['nombre' => 'Producto oculto']);
        $hija = CategoriaProducto::factory()->hijaDe($inactiva)->create(['nombre' => 'Hija oculta']);
        Producto::factory()->for($hija, 'categoria')->create(['nombre' => 'Producto de hija oculta']);
        $this->producto(['nombre' => 'Visible']);

        $this->get($this->url())
            ->assertSee('Visible')
            ->assertDontSee('Oculta')
            ->assertDontSee('Producto oculto')
            ->assertDontSee('Hija oculta');
    }

    public function test_subcategories_appear_under_their_category(): void
    {
        $hija = CategoriaProducto::factory()->hijaDe($this->categoria)->create(['nombre' => 'Frías']);
        Producto::factory()->for($hija, 'categoria')->create(['nombre' => 'Limonada']);

        $this->get($this->url())
            ->assertSee('data-subchips="'.$this->categoria->id.'"', false)
            ->assertSeeInOrder(['Pollos', 'Frías', 'Limonada']);
    }

    public function test_empty_menu_state(): void
    {
        $this->get($this->url())->assertOk()->assertSee('todavía no publica su carta');
    }

    public function test_closed_restaurant_shows_a_notice_and_still_shows_the_menu(): void
    {
        $this->restaurante->update(['estado_local' => 'cerrado']);
        $this->producto(['nombre' => 'Anticuchos']);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Cerrado')
            ->assertSee('Este local está cerrado ahora.')
            ->assertSee('Anticuchos');
    }

    public function test_continue_button_is_visible_but_disabled(): void
    {
        $this->get($this->url())
            ->assertSee('Continuar con el pedido')
            ->assertSee('Disponible pronto');
    }

    public function test_share_metadata_is_present_and_escaped(): void
    {
        $this->restaurante->update(['nombre' => 'Pollos "Don" <Pepe>', 'logo' => 'logos/uno.png']);

        $this->get($this->url())
            ->assertSee('<title>Pollos &quot;Don&quot; &lt;Pepe&gt; · FOORA</title>', false)
            ->assertSee('<meta property="og:title" content="Pollos &quot;Don&quot; &lt;Pepe&gt;">', false)
            ->assertSee('<meta property="og:description" content="Pollo a la leña.">', false)
            ->assertSee('property="og:image" content="'.asset('storage/logos/uno.png').'"', false);
    }

    public function test_product_data_from_the_restaurant_cannot_break_out_of_the_page(): void
    {
        $ataque = '</script><script>alert(1)</script>';
        $producto = $this->producto(['nombre' => $ataque, 'descripcion' => $ataque, 'tipo' => 'variantes']);
        ProductoVariante::factory()->for($producto)->create(['nombre' => $ataque]);
        $grupo = GrupoOpcion::factory()->for($this->restaurante)->create(['nombre' => $ataque]);
        Opcion::factory()->for($grupo, 'grupo')->create(['nombre' => $ataque]);
        $producto->gruposOpcion()->attach($grupo);
        $this->categoria->update(['nombre' => $ataque]);
        $this->restaurante->update(['descripcion' => $ataque]);

        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('u003Cscript', $html);
    }

    public function test_quotes_in_product_data_cannot_break_the_embedded_json(): void
    {
        $this->producto(['nombre' => "Pollo 'a la \"brasa\"' \\ & más"]);

        $html = $this->get($this->url())->assertOk()->getContent();

        preg_match('/window\.FOORA_VITRINA = JSON\.parse\(\'(.*?)\'\);<\/script>/s', $html, $coincidencia);

        $this->assertNotEmpty($coincidencia, 'El JSON debe ir dentro de JSON.parse.');
        $this->assertStringNotContainsString("'", $coincidencia[1]);
    }

    public function test_page_does_not_show_data_from_another_restaurant(): void
    {
        $otro = Restaurante::factory()->create(['nombre' => 'Cevichería Ajena']);
        $categoriaAjena = CategoriaProducto::factory()->for($otro)->create(['nombre' => 'Categoría ajena']);
        Producto::factory()->for($categoriaAjena, 'categoria')->create(['nombre' => 'Ceviche ajeno']);
        $this->producto(['nombre' => 'Pollo propio']);

        $this->get($this->url())
            ->assertSee('Pollo propio')
            ->assertDontSee('Cevichería Ajena')
            ->assertDontSee('Categoría ajena')
            ->assertDontSee('Ceviche ajeno');
    }

    public function test_foreign_or_inactive_group_linked_to_a_product_is_not_published(): void
    {
        $producto = $this->producto();
        $otro = Restaurante::factory()->create();
        $ajeno = GrupoOpcion::factory()->for($otro)->create(['nombre' => 'Grupo ajeno']);
        $inactivo = GrupoOpcion::factory()->for($this->restaurante)->inactivo()->create(['nombre' => 'Grupo apagado']);
        $propio = GrupoOpcion::factory()->for($this->restaurante)->create(['nombre' => 'Grupo propio']);
        $producto->gruposOpcion()->attach([$ajeno->id, $inactivo->id, $propio->id]);

        $this->get($this->url())
            ->assertSee('Grupo propio')
            ->assertDontSee('Grupo ajeno')
            ->assertDontSee('Grupo apagado');
    }

    public function test_logged_in_admin_of_another_restaurant_sees_only_the_public_restaurant(): void
    {
        $otro = Restaurante::factory()->create(['nombre' => 'Local del admin']);
        $categoriaAjena = CategoriaProducto::factory()->for($otro)->create(['nombre' => 'Categoría del admin']);
        Producto::factory()->for($categoriaAjena, 'categoria')->create(['nombre' => 'Plato del admin']);
        $grupo = GrupoOpcion::factory()->for($otro)->create(['nombre' => 'Grupo del admin']);

        $producto = $this->producto(['nombre' => 'Pollo de la vitrina']);
        $propio = GrupoOpcion::factory()->for($this->restaurante)->create(['nombre' => 'Grupo de la vitrina']);
        Opcion::factory()->for($propio, 'grupo')->create(['nombre' => 'Opción de la vitrina']);
        $producto->gruposOpcion()->attach([$propio->id, $grupo->id]);
        $this->restaurante->horariosAtencion()->create(['dia_semana' => 1, 'cerrado' => true]);

        // Con la sesión del admin, el scope global filtraría por su restaurante.
        $this->actingAs(User::factory()->admin($otro)->create());
        $this->assertSame(0, CategoriaProducto::where('restaurante_id', $this->restaurante->id)->count());

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Pollo de la vitrina')
            ->assertSee('Grupo de la vitrina')
            ->assertSee('Opción de la vitrina')
            ->assertDontSee('Plato del admin')
            ->assertDontSee('Grupo del admin')
            ->assertDontSee('Categoría del admin');
    }

    public function test_query_count_does_not_grow_with_the_menu(): void
    {
        $this->productos(3);
        $pocos = $this->consultasDeLaPagina();

        $this->productos(30);
        $muchos = $this->consultasDeLaPagina();

        $this->assertSame($pocos, $muchos);
        $this->assertLessThanOrEqual(9, $muchos);
    }

    private function productos(int $cantidad): void
    {
        $grupo = GrupoOpcion::factory()->for($this->restaurante)->create();
        Opcion::factory()->count(3)->for($grupo, 'grupo')->create();

        Producto::factory()->count($cantidad)->for($this->categoria, 'categoria')->create(['tipo' => 'variantes'])
            ->each(function (Producto $producto) use ($grupo): void {
                ProductoVariante::factory()->count(2)->for($producto)->create();
                $producto->gruposOpcion()->attach($grupo);
            });
    }

    private function consultasDeLaPagina(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($this->url())->assertOk();

        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $consultas;
    }
}
