<?php

namespace Tests\Feature\Vitrina;

use App\Models\CategoriaProducto;
use App\Models\GrupoOpcion;
use App\Models\Opcion;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ValidarCarritoTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private CategoriaProducto $categoria;

    private Producto $simple;

    private Producto $conVariantes;

    private ProductoVariante $personal;

    private GrupoOpcion $adicionales;

    private Opcion $tocino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->create(['slug' => 'polleria-central']);
        $this->categoria = CategoriaProducto::factory()->for($this->restaurante)->create();

        $this->simple = Producto::factory()->for($this->categoria, 'categoria')->create(['nombre' => 'Anticuchos', 'precio' => 18.00]);
        $this->conVariantes = Producto::factory()->for($this->categoria, 'categoria')->create(['nombre' => 'Chaufa', 'tipo' => 'variantes', 'precio' => 22.00]);
        $this->personal = ProductoVariante::factory()->for($this->conVariantes)->create(['nombre' => 'Personal', 'precio' => 22.00]);

        $this->adicionales = GrupoOpcion::factory()->for($this->restaurante)->multiple()->create(['nombre' => 'Adicionales']);
        $this->tocino = Opcion::factory()->for($this->adicionales, 'grupo')->create(['nombre' => 'Tocino', 'precio_extra' => 5.00]);
        $this->conVariantes->gruposOpcion()->attach($this->adicionales);
    }

    /**
     * @param  list<array<string, mixed>>  $lineas
     */
    private function validar(array $lineas, string $slug = 'polleria-central'): TestResponse
    {
        return $this->postJson(route('vitrina.carrito.validar', $slug), ['lineas' => $lineas]);
    }

    /**
     * @param  list<int>  $opciones
     * @return array<string, mixed>
     */
    private function linea(Producto $producto, ?ProductoVariante $variante = null, array $opciones = [], int $cantidad = 1): array
    {
        return ['producto_id' => $producto->id, 'variante_id' => $variante?->id, 'opciones' => $opciones, 'cantidad' => $cantidad];
    }

    public function test_valid_cart_returns_official_prices_and_subtotal(): void
    {
        $this->validar([
            $this->linea($this->simple, cantidad: 2),
            $this->linea($this->conVariantes, $this->personal, [$this->tocino->id]),
        ])
            ->assertOk()
            ->assertJsonPath('subtotal', '63.00')
            ->assertJsonPath('lineas.0.valida', true)
            ->assertJsonPath('lineas.0.nombre', 'Anticuchos')
            ->assertJsonPath('lineas.0.precio_unitario', '18.00')
            ->assertJsonPath('lineas.0.subtotal', '36.00')
            ->assertJsonPath('lineas.1.variante_nombre', 'Personal')
            ->assertJsonPath('lineas.1.opciones.0.nombre', 'Tocino')
            ->assertJsonPath('lineas.1.opciones.0.precio_extra', '5.00')
            ->assertJsonPath('lineas.1.precio_unitario', '27.00')
            ->assertJsonPath('lineas.1.errores', []);
    }

    public function test_invalid_lines_report_their_errors_and_do_not_add_to_the_subtotal(): void
    {
        $agotado = Producto::factory()->for($this->categoria, 'categoria')->create(['nombre' => 'Parrilla', 'disponible' => false]);

        $this->validar([
            $this->linea($this->simple),
            $this->linea($agotado),
            $this->linea($this->conVariantes),
            $this->linea($this->simple, opciones: [$this->tocino->id]),
            $this->linea($this->simple, cantidad: 51),
        ])
            ->assertOk()
            ->assertJsonPath('subtotal', '18.00')
            ->assertJsonPath('lineas.0.valida', true)
            ->assertJsonPath('lineas.1.valida', false)
            ->assertJsonPath('lineas.1.errores.0', '«Parrilla» no está disponible.')
            ->assertJsonPath('lineas.1.precio_unitario', null)
            ->assertJsonPath('lineas.2.errores.0', 'Elige una variante de «Chaufa».')
            ->assertJsonPath('lineas.3.errores.0', 'Una de las opciones elegidas no pertenece a «Anticuchos».')
            ->assertJsonPath('lineas.4.errores.0', 'La cantidad máxima por línea es 50.');
    }

    public function test_sold_out_option_and_variant_are_reported(): void
    {
        $this->tocino->update(['disponible' => false]);
        $agotada = ProductoVariante::factory()->agotada()->for($this->conVariantes)->create(['nombre' => 'Familiar']);

        $this->validar([
            $this->linea($this->conVariantes, $this->personal, [$this->tocino->id]),
            $this->linea($this->conVariantes, $agotada),
        ])
            ->assertJsonPath('lineas.0.errores.0', '«Tocino» no está disponible.')
            ->assertJsonPath('lineas.1.errores.0', 'La variante «Familiar» no está disponible.');
    }

    public function test_client_prices_are_ignored(): void
    {
        $this->validar([[
            ...$this->linea($this->conVariantes, $this->personal, [$this->tocino->id], 2),
            'precio' => 0.01,
            'precio_unitario' => 0.01,
            'subtotal' => 0.01,
        ]])
            ->assertOk()
            ->assertJsonPath('lineas.0.precio_unitario', '27.00')
            ->assertJsonPath('lineas.0.subtotal', '54.00')
            ->assertJsonPath('subtotal', '54.00');

        $this->postJson(route('vitrina.carrito.validar', 'polleria-central'), [
            'subtotal' => 0.01,
            'lineas' => [$this->linea($this->simple)],
        ])->assertJsonPath('subtotal', '18.00');
    }

    public function test_option_with_an_embedded_price_is_rejected(): void
    {
        $this->validar([$this->linea($this->conVariantes, $this->personal, [['id' => $this->tocino->id, 'precio_extra' => 0]])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lineas.0.opciones.0');
    }

    public function test_ids_from_another_restaurant_are_rejected(): void
    {
        $otro = Restaurante::factory()->create(['slug' => 'otro-local']);
        $categoria = CategoriaProducto::factory()->for($otro)->create();
        $ajeno = Producto::factory()->for($categoria, 'categoria')->create(['nombre' => 'Ceviche ajeno', 'tipo' => 'variantes', 'precio' => 30]);
        $varianteAjena = ProductoVariante::factory()->for($ajeno)->create();
        $grupoAjeno = GrupoOpcion::factory()->for($otro)->multiple()->create();
        $opcionAjena = Opcion::factory()->for($grupoAjeno, 'grupo')->create();

        $this->validar([
            $this->linea($ajeno, $varianteAjena),
            $this->linea($this->conVariantes, $varianteAjena),
            $this->linea($this->conVariantes, $this->personal, [$opcionAjena->id]),
        ])
            ->assertOk()
            ->assertJsonPath('subtotal', '0.00')
            ->assertJsonPath('lineas.0.valida', false)
            ->assertJsonPath('lineas.0.errores.0', 'Este producto ya no está en la carta.')
            ->assertJsonPath('lineas.1.errores.0', 'La variante elegida no pertenece a «Chaufa».')
            ->assertJsonPath('lineas.2.errores.0', 'Una de las opciones elegidas no pertenece a «Chaufa».')
            ->assertJsonMissingPath('lineas.0.nombre.x')
            ->assertDontSee('Ceviche ajeno');
    }

    public function test_foreign_group_linked_to_a_product_does_not_count(): void
    {
        $otro = Restaurante::factory()->create();
        $grupoAjeno = GrupoOpcion::factory()->for($otro)->obligatorio()->create();
        $opcionAjena = Opcion::factory()->for($grupoAjeno, 'grupo')->create();
        $this->simple->gruposOpcion()->attach($grupoAjeno);

        $this->validar([
            $this->linea($this->simple),
            $this->linea($this->simple, opciones: [$opcionAjena->id]),
        ])
            ->assertJsonPath('lineas.0.valida', true)
            ->assertJsonPath('lineas.1.valida', false);
    }

    public function test_logged_in_admin_of_another_restaurant_still_validates_against_this_restaurant(): void
    {
        $otro = Restaurante::factory()->create();
        $categoriaOtro = CategoriaProducto::factory()->for($otro)->create();
        $productoDelAdmin = Producto::factory()->for($categoriaOtro, 'categoria')->create();

        $this->actingAs(User::factory()->admin($otro)->create());

        // El scope global filtra por el local del admin; el endpoint no debe depender de él.
        $this->assertNull(Producto::find($this->simple->id));

        $this->validar([
            $this->linea($this->conVariantes, $this->personal, [$this->tocino->id]),
            $this->linea($productoDelAdmin),
        ])
            ->assertOk()
            ->assertJsonPath('lineas.0.valida', true)
            ->assertJsonPath('lineas.0.precio_unitario', '27.00')
            ->assertJsonPath('lineas.1.valida', false)
            ->assertJsonPath('subtotal', '27.00');
    }

    public function test_hidden_or_missing_restaurant_is_not_found(): void
    {
        Restaurante::factory()->create(['slug' => 'oculto', 'visible_en_app' => false]);

        $this->validar([], 'oculto')->assertNotFound();
        $this->validar([], 'no-existe')->assertNotFound();
    }

    public function test_closed_restaurant_still_prices_the_cart(): void
    {
        $this->restaurante->update(['estado_local' => 'cerrado']);

        $this->validar([$this->linea($this->simple)])->assertOk()->assertJsonPath('subtotal', '18.00');
    }

    public function test_empty_cart_is_valid(): void
    {
        $this->validar([])->assertOk()->assertJsonPath('lineas', [])->assertJsonPath('subtotal', '0.00');
    }

    public function test_cart_with_too_many_lines_is_rejected(): void
    {
        $this->validar(array_fill(0, 31, $this->linea($this->simple)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lineas');
    }

    public function test_malformed_lines_are_rejected(): void
    {
        $this->validar([['producto_id' => 'abc', 'cantidad' => 1]])->assertUnprocessable();
        $this->validar([['cantidad' => 1]])->assertUnprocessable();
        $this->validar([['producto_id' => $this->simple->id]])->assertUnprocessable();
        $this->postJson(route('vitrina.carrito.validar', 'polleria-central'), [])->assertUnprocessable();
    }

    public function test_endpoint_needs_no_csrf_token_or_session(): void
    {
        $respuesta = $this->withHeader('Accept', 'application/json')
            ->post(route('vitrina.carrito.validar', 'polleria-central'), ['lineas' => [$this->linea($this->simple)]])
            ->assertOk();

        $this->assertCount(0, array_filter(
            $respuesta->headers->getCookies(),
            fn ($cookie): bool => str_contains($cookie->getName(), 'session'),
        ));
    }

    public function test_endpoint_is_limited_to_60_requests_per_minute(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->validar([])->assertOk();
        }

        $this->validar([])->assertTooManyRequests();
    }

    public function test_query_count_does_not_grow_with_the_lines(): void
    {
        $this->assertSame($this->consultas(1), $this->consultas(30));
    }

    private function consultas(int $lineas): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $linea = $this->linea($this->conVariantes, $this->personal, [$this->tocino->id]);
        $this->validar(array_fill(0, $lineas, $linea))->assertOk();

        $consultas = collect(DB::getQueryLog())
            ->reject(fn (array $consulta): bool => str_contains($consulta['query'], '"cache'))
            ->count();

        DB::disableQueryLog();

        return $consultas;
    }
}
