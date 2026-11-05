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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GrupoOpcionTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    private CategoriaProducto $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->create();
        $this->admin = User::factory()->admin($this->restaurante)->create();
        $this->categoria = CategoriaProducto::factory()->for($this->restaurante)->create();
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Adicionales',
            'tipo' => 'multiple',
            'obligatorio' => '0',
            'min_opciones' => '0',
            'max_opciones' => '',
            'activo' => '1',
            'opciones' => [
                ['id' => '', 'nombre' => 'Piña', 'precio_extra' => '3.00', 'disponible' => '1'],
                ['id' => '', 'nombre' => 'Tocino', 'precio_extra' => '5', 'disponible' => '0'],
            ],
        ], $cambios);
    }

    private function grupo(string $nombre = 'Guarnición'): GrupoOpcion
    {
        return GrupoOpcion::factory()->for($this->restaurante)->create(['nombre' => $nombre]);
    }

    private function producto(string $nombre = 'Pollo entero'): Producto
    {
        return Producto::factory()->for($this->categoria, 'categoria')->create(['nombre' => $nombre]);
    }

    public function test_listing_shows_groups_with_rule_options_and_product_count(): void
    {
        $grupo = GrupoOpcion::factory()->for($this->restaurante)->multiple(1, 3)->create(['nombre' => 'Salsas']);
        Opcion::factory()->for($grupo, 'grupo')->create(['nombre' => 'Ají', 'precio_extra' => 1.50]);
        $grupo->productos()->attach($this->producto()->id);

        $this->actingAs($this->admin)
            ->get(route('panel.carta.grupos.index'))
            ->assertOk()
            ->assertSee('Salsas')
            ->assertSee('Elige de 1 a 3')
            ->assertSee('Ají')
            ->assertSee('1.50')
            ->assertSee('Obligatorio')
            ->assertSee('data-disponibilidad', false);
    }

    public function test_listing_shows_the_empty_state(): void
    {
        $this->actingAs($this->admin)
            ->get(route('panel.carta.grupos.index'))
            ->assertOk()
            ->assertSee('Todavía no tienes grupos de opciones')
            ->assertSee(route('panel.carta.grupos.create'));
    }

    public function test_search_filters_and_shows_the_no_results_state(): void
    {
        $this->grupo('Guarnición');
        $this->grupo('Gaseosa');
        $this->actingAs($this->admin);

        $this->get(route('panel.carta.grupos.index', ['q' => 'gase']))
            ->assertSee('Gaseosa')
            ->assertDontSee('Guarnición');

        $this->get(route('panel.carta.grupos.index', ['q' => 'xyz']))
            ->assertOk()
            ->assertSee('Ningún grupo coincide con');
    }

    public function test_admin_creates_a_group_with_its_options_in_order(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.carta.grupos.store'), $this->datos())
            ->assertRedirect(route('panel.carta.grupos.index'))
            ->assertSessionHas('status');

        $grupo = GrupoOpcion::where('nombre', 'Adicionales')->firstOrFail();

        $this->assertSame($this->restaurante->id, $grupo->restaurante_id);
        $this->assertSame('multiple', $grupo->tipo);
        $this->assertSame(0, $grupo->min_opciones);
        $this->assertNull($grupo->max_opciones);

        $this->assertSame(['Piña', 'Tocino'], $grupo->opciones->pluck('nombre')->all());
        $this->assertSame([0, 1], $grupo->opciones->pluck('orden')->all());
        $this->assertSame(['3.00', '5.00'], $grupo->opciones->pluck('precio_extra')->all());
        $this->assertSame([true, false], $grupo->opciones->pluck('disponible')->all());
    }

    public function test_single_choice_group_stores_maximum_one_and_minimum_from_required(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.carta.grupos.store'), $this->datos(['tipo' => 'unica', 'obligatorio' => '1', 'min_opciones' => '7', 'max_opciones' => '9']))
            ->assertSessionHasNoErrors();

        $grupo = GrupoOpcion::firstOrFail();
        $this->assertSame(1, $grupo->min_opciones);
        $this->assertSame(1, $grupo->max_opciones);

        $this->put(route('panel.carta.grupos.update', $grupo), $this->datos(['tipo' => 'unica', 'obligatorio' => '0', 'min_opciones' => '3']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $grupo->fresh()->min_opciones);
    }

    public function test_empty_rows_are_ignored_and_a_comma_price_and_empty_extra_are_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.carta.grupos.store'), $this->datos(['opciones' => [
                ['nombre' => 'Piña', 'precio_extra' => '3,50', 'disponible' => '1'],
                ['nombre' => '', 'precio_extra' => '', 'disponible' => '1'],
                ['nombre' => 'Sin costo', 'precio_extra' => '', 'disponible' => '1'],
            ]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['3.50', '0.00'], GrupoOpcion::firstOrFail()->opciones->pluck('precio_extra')->all());
    }

    public function test_update_keeps_ids_reorders_adds_and_removes_options(): void
    {
        $grupo = $this->grupo();
        $primera = Opcion::factory()->for($grupo, 'grupo')->create(['nombre' => 'Primera', 'orden' => 0]);
        $segunda = Opcion::factory()->for($grupo, 'grupo')->create(['nombre' => 'Segunda', 'orden' => 1]);
        $quitada = Opcion::factory()->for($grupo, 'grupo')->create(['nombre' => 'Quitada', 'orden' => 2]);

        $this->actingAs($this->admin)
            ->put(route('panel.carta.grupos.update', $grupo), $this->datos(['nombre' => 'Renombrado', 'opciones' => [
                ['id' => $segunda->id, 'nombre' => 'Segunda', 'precio_extra' => '1.00', 'disponible' => '1'],
                ['id' => $primera->id, 'nombre' => 'Primera editada', 'precio_extra' => '2.00', 'disponible' => '1'],
                ['id' => '', 'nombre' => 'Nueva', 'precio_extra' => '0', 'disponible' => '1'],
            ]]))
            ->assertRedirect(route('panel.carta.grupos.index'));

        $grupo->refresh();

        $this->assertSame('Renombrado', $grupo->nombre);
        $this->assertSame(['Segunda', 'Primera editada', 'Nueva'], $grupo->opciones->pluck('nombre')->all());
        $this->assertSame([$segunda->id, $primera->id], $grupo->opciones->take(2)->pluck('id')->all());
        $this->assertModelMissing($quitada);
    }

    public function test_an_option_id_from_another_group_is_treated_as_a_new_option(): void
    {
        $grupo = $this->grupo();
        $ajena = Opcion::factory()->for($this->grupo('Otro'), 'grupo')->create(['nombre' => 'Original']);

        $this->actingAs($this->admin)
            ->put(route('panel.carta.grupos.update', $grupo), $this->datos(['opciones' => [
                ['id' => $ajena->id, 'nombre' => 'Intrusa', 'precio_extra' => '0', 'disponible' => '1'],
            ]]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Original', $ajena->fresh()->nombre);
        $this->assertSame(['Intrusa'], $grupo->fresh()->opciones->pluck('nombre')->all());
    }

    public function test_group_without_products_is_deleted_with_its_options(): void
    {
        $grupo = $this->grupo();
        $opcion = Opcion::factory()->for($grupo, 'grupo')->create();

        $this->actingAs($this->admin)
            ->delete(route('panel.carta.grupos.destroy', $grupo))
            ->assertRedirect(route('panel.carta.grupos.index'))
            ->assertSessionHas('status');

        $this->assertModelMissing($grupo);
        $this->assertModelMissing($opcion);
    }

    public function test_assigned_group_cannot_be_deleted_and_the_message_says_how_many(): void
    {
        $grupo = $this->grupo();
        $grupo->productos()->attach([$this->producto('Uno')->id, $this->producto('Dos')->id, $this->producto('Tres')->id]);

        $this->actingAs($this->admin)
            ->delete(route('panel.carta.grupos.destroy', $grupo))
            ->assertRedirect(route('panel.carta.grupos.index'))
            ->assertSessionHas('error', 'No puedes eliminar «Guarnición» porque está asignado a 3 productos. Quítalo de esos productos primero.');

        $this->assertModelExists($grupo);

        $otro = $this->grupo('Sabor');
        $otro->productos()->attach($this->producto('Cuatro')->id);

        $this->delete(route('panel.carta.grupos.destroy', $otro))
            ->assertSessionHas('error', 'No puedes eliminar «Sabor» porque está asignado a 1 producto. Quítalo de esos productos primero.');
    }

    public function test_a_deleted_product_does_not_block_deleting_the_group(): void
    {
        $grupo = $this->grupo();
        $producto = $this->producto();
        $grupo->productos()->attach($producto->id);
        $producto->delete();

        $this->actingAs($this->admin)
            ->delete(route('panel.carta.grupos.destroy', $grupo))
            ->assertSessionHas('status');

        $this->assertModelMissing($grupo);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function datosInvalidos(): array
    {
        return [
            'sin nombre' => [['nombre' => ''], 'nombre', 'Escribe el nombre del grupo.'],
            'tipo inválido' => [['tipo' => 'cualquiera'], 'tipo', 'Elige si es de elección única o múltiple.'],
            'sin opciones' => [['opciones' => []], 'opciones', 'Agrega al menos una opción.'],
            'mínimo sin obligatorio' => [['min_opciones' => '1', 'obligatorio' => '0'], 'min_opciones', 'Si el mínimo es mayor que 0, el grupo debe ser obligatorio.'],
            'obligatorio sin mínimo' => [['min_opciones' => '0', 'obligatorio' => '1'], 'min_opciones', 'Un grupo obligatorio necesita un mínimo de al menos 1.'],
            'máximo menor que mínimo' => [['min_opciones' => '2', 'max_opciones' => '1', 'obligatorio' => '1'], 'max_opciones', 'El máximo no puede ser menor que el mínimo.'],
            'mínimo mayor que opciones' => [['min_opciones' => '3', 'obligatorio' => '1'], 'min_opciones', 'El mínimo no puede superar las opciones del grupo (2).'],
            'mínimo negativo' => [['min_opciones' => '-1'], 'min_opciones', 'El mínimo no puede ser negativo.'],
            'máximo cero' => [['max_opciones' => '0'], 'max_opciones', 'El máximo debe ser al menos 1. Déjalo vacío si no tiene límite.'],
            'opción sin nombre' => [['opciones' => [['nombre' => '', 'precio_extra' => '2', 'disponible' => '1']]], 'opciones.0.nombre', 'Cada opción necesita un nombre.'],
            'extra negativo' => [['opciones' => [['nombre' => 'A', 'precio_extra' => '-1', 'disponible' => '1']]], 'opciones.0.precio_extra', 'El precio extra no puede ser negativo.'],
            'extra con 3 decimales' => [['opciones' => [['nombre' => 'A', 'precio_extra' => '1.234', 'disponible' => '1']]], 'opciones.0.precio_extra', 'El precio admite hasta 2 decimales.'],
            'opciones duplicadas' => [['opciones' => [['nombre' => 'Piña', 'precio_extra' => '1', 'disponible' => '1'], ['nombre' => 'piña', 'precio_extra' => '1', 'disponible' => '1']]], 'opciones.0.nombre', 'Hay dos opciones con el mismo nombre.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    #[DataProvider('datosInvalidos')]
    public function test_invalid_groups_are_rejected_with_a_clear_message(array $cambios, string $campo, string $mensaje): void
    {
        $this->actingAs($this->admin)
            ->from(route('panel.carta.grupos.create'))
            ->post(route('panel.carta.grupos.store'), $this->datos($cambios))
            ->assertRedirect(route('panel.carta.grupos.create'))
            ->assertSessionHasErrors([$campo => $mensaje]);

        $this->assertDatabaseCount('grupos_opcion', 0);
    }

    public function test_required_multiple_group_with_a_valid_range_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.carta.grupos.store'), $this->datos(['obligatorio' => '1', 'min_opciones' => '1', 'max_opciones' => '2']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Elige de 1 a 2', GrupoOpcion::firstOrFail()->descripcionRegla());
    }

    public function test_group_form_renders_for_new_and_existing_groups(): void
    {
        $grupo = $this->grupo();
        Opcion::factory()->for($grupo, 'grupo')->create(['nombre' => 'Papas']);
        $this->actingAs($this->admin);

        $this->get(route('panel.carta.grupos.create'))->assertOk()->assertSee('Nuevo grupo de opciones');
        $this->get(route('panel.carta.grupos.edit', $grupo))->assertOk()->assertSee('Papas');
    }

    public function test_product_form_lists_the_restaurant_groups(): void
    {
        $this->grupo('Gaseosa');

        $this->actingAs($this->admin)
            ->get(route('panel.carta.productos.create'))
            ->assertOk()
            ->assertSee('data-asignado-select', false)
            ->assertSee('Gaseosa');
    }

    public function test_product_form_invites_to_create_a_group_when_there_are_none(): void
    {
        $this->actingAs($this->admin)
            ->get(route('panel.carta.productos.create'))
            ->assertSee('Todavía no tienes grupos de opciones')
            ->assertSee(route('panel.carta.grupos.create'));
    }

    public function test_product_stores_its_groups_in_the_chosen_order_and_they_can_be_changed(): void
    {
        $producto = $this->producto();
        $primero = $this->grupo('Primero');
        $segundo = $this->grupo('Segundo');
        $datos = [
            'nombre' => $producto->nombre,
            'categoria_id' => $this->categoria->id,
            'tipo' => 'simple',
            'precio' => '30',
            'estacion' => 'cocina',
        ];

        $this->actingAs($this->admin)
            ->put(route('panel.carta.productos.update', $producto), [...$datos, 'grupos' => [$segundo->id, $primero->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Segundo', 'Primero'], $producto->fresh()->gruposOpcion->pluck('nombre')->all());

        $this->get(route('panel.carta.productos.edit', $producto))
            ->assertSeeInOrder(['Segundo', 'Primero']);

        $this->put(route('panel.carta.productos.update', $producto), [...$datos, 'grupos' => [$primero->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Primero'], $producto->fresh()->gruposOpcion->pluck('nombre')->all());

        $this->put(route('panel.carta.productos.update', $producto), $datos)->assertSessionHasNoErrors();
        $this->assertCount(0, $producto->fresh()->gruposOpcion);
    }

    public function test_a_group_is_shared_by_many_products(): void
    {
        $grupo = $this->grupo();
        $datos = ['categoria_id' => $this->categoria->id, 'tipo' => 'simple', 'precio' => '10', 'estacion' => 'cocina', 'grupos' => [$grupo->id]];

        $this->actingAs($this->admin);
        $this->post(route('panel.carta.productos.store'), [...$datos, 'nombre' => 'Uno'])->assertSessionHasNoErrors();
        $this->post(route('panel.carta.productos.store'), [...$datos, 'nombre' => 'Dos'])->assertSessionHasNoErrors();

        $this->assertSame(2, $grupo->productos()->count());
    }

    public function test_repeated_group_on_a_product_is_rejected(): void
    {
        $grupo = $this->grupo();

        $this->actingAs($this->admin)
            ->post(route('panel.carta.productos.store'), ['nombre' => 'Uno', 'categoria_id' => $this->categoria->id, 'tipo' => 'simple', 'precio' => '10', 'estacion' => 'cocina', 'grupos' => [$grupo->id, $grupo->id]])
            ->assertSessionHasErrors(['grupos.0' => 'Un grupo no se puede asignar dos veces.']);
    }

    public function test_admin_changes_option_and_variant_availability(): void
    {
        $grupo = $this->grupo();
        $opcion = Opcion::factory()->for($grupo, 'grupo')->create(['nombre' => 'Piña']);
        $producto = $this->producto();
        $variante = ProductoVariante::factory()->for($producto)->create();

        $this->actingAs($this->admin)
            ->patchJson(route('panel.carta.grupos.opciones.disponibilidad', [$grupo, $opcion]), ['disponible' => false])
            ->assertOk()
            ->assertJson(['disponible' => false, 'mensaje' => '«Piña» quedó como agotada.']);

        $this->patchJson(route('panel.carta.productos.variantes.disponibilidad', [$producto, $variante]), ['disponible' => false])
            ->assertOk()
            ->assertJson(['disponible' => false]);

        $this->assertFalse($opcion->fresh()->disponible);
        $this->assertFalse($variante->fresh()->disponible);
    }

    public function test_availability_rejects_invalid_values(): void
    {
        $grupo = $this->grupo();
        $opcion = Opcion::factory()->for($grupo, 'grupo')->create();

        $this->actingAs($this->admin)
            ->patchJson(route('panel.carta.grupos.opciones.disponibilidad', [$grupo, $opcion]), ['disponible' => 'quizás'])
            ->assertUnprocessable();

        $this->assertTrue($opcion->fresh()->disponible);
    }
}
