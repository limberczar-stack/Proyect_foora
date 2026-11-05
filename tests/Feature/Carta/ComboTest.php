<?php

namespace Tests\Feature\Carta;

use App\Models\CategoriaProducto;
use App\Models\GrupoOpcion;
use App\Models\Producto;
use App\Models\Restaurante;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComboTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CategoriaProducto $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $restaurante = Restaurante::factory()->create();
        $this->admin = User::factory()->admin($restaurante)->create();
        $this->categoria = CategoriaProducto::factory()->for($restaurante)->create();
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Banquete para 5',
            'categoria_id' => $this->categoria->id,
            'tipo' => 'combo',
            'descripcion' => 'Incluye chaufa familiar y tallarín saltado.',
            'precio' => '129.00',
            'personas' => '5',
            'estacion' => 'cocina',
        ], $cambios);
    }

    public function test_admin_creates_a_combo_with_fixed_price_and_people(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.carta.productos.store'), $this->datos())
            ->assertRedirect(route('panel.carta.productos.index'));

        $combo = Producto::where('nombre', 'Banquete para 5')->firstOrFail();

        $this->assertTrue($combo->esCombo());
        $this->assertSame(5, $combo->personas);
        $this->assertSame('129.00', $combo->precio);
    }

    public function test_combo_needs_people_price_and_description(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.carta.productos.store'), $this->datos(['personas' => '', 'precio' => '', 'descripcion' => '']))
            ->assertSessionHasErrors([
                'personas' => 'Indica para cuántas personas es el combo.',
                'precio' => 'Escribe el precio.',
                'descripcion' => 'Escribe lo que incluye el combo.',
            ]);
    }

    public function test_people_must_be_within_range(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('panel.carta.productos.store'), $this->datos(['personas' => '0']))
            ->assertSessionHasErrors('personas');
        $this->post(route('panel.carta.productos.store'), $this->datos(['personas' => (string) (Producto::PERSONAS_MAXIMO + 1)]))
            ->assertSessionHasErrors('personas');
    }

    public function test_changing_a_combo_to_simple_clears_people(): void
    {
        $combo = Producto::factory()->for($this->categoria, 'categoria')->create(['tipo' => 'combo', 'personas' => 4]);

        $this->actingAs($this->admin)
            ->put(route('panel.carta.productos.update', $combo), $this->datos(['tipo' => 'simple', 'personas' => '4']))
            ->assertSessionHasNoErrors();

        $combo->refresh();
        $this->assertSame('simple', $combo->tipo);
        $this->assertNull($combo->personas);
    }

    public function test_listing_and_form_show_the_combo(): void
    {
        $combo = Producto::factory()->for($this->categoria, 'categoria')->create(['tipo' => 'combo', 'personas' => 5]);

        $this->actingAs($this->admin)
            ->get(route('panel.carta.productos.index'))
            ->assertSee('Para 5 personas');

        $this->get(route('panel.carta.productos.edit', $combo))
            ->assertOk()
            ->assertSee('value="5"', false);
    }

    public function test_demo_seeder_creates_groups_and_combo_and_can_be_repeated(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $buenSabor = Restaurante::where('slug', 'el-buen-sabor')->firstOrFail();
        $chifa = Restaurante::where('slug', 'chifa-dragon-dorado')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['Elige tu guarnición', 'Adicionales', 'Gaseosa'],
            GrupoOpcion::where('restaurante_id', $buenSabor->id)->pluck('nombre')->all(),
        );

        $adicionales = GrupoOpcion::where('restaurante_id', $buenSabor->id)->where('nombre', 'Adicionales')->firstOrFail();
        $this->assertSame(['3.00', '3.00', '3.00', '5.00'], $adicionales->opciones->pluck('precio_extra')->all());

        $combo = Producto::where('restaurante_id', $chifa->id)->where('tipo', 'combo')->firstOrFail();
        $this->assertSame(5, $combo->personas);
        $this->assertSame(['Chaufa de'], $combo->gruposOpcion->pluck('nombre')->all());
        $this->assertSame(['Pollo', 'Chancho'], $combo->gruposOpcion->first()->opciones->pluck('nombre')->all());

        $tipakay = Producto::where('restaurante_id', $chifa->id)->where('nombre', 'like', 'Tipakay%')->firstOrFail();
        $this->assertSame(['Sabor'], $tipakay->gruposOpcion->pluck('nombre')->all());
    }

    public function test_demo_seeder_does_not_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        (new DemoSeeder)->run();

        $this->assertDatabaseMissing('grupos_opcion', ['nombre' => 'Adicionales']);
    }
}
