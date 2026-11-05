<?php

namespace Tests\Feature\Carta;

use App\Models\CategoriaProducto;
use App\Models\GrupoOpcion;
use App\Models\Opcion;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\Restaurante;
use App\Models\User;
use App\Services\CalculadorLineaPedido;
use App\Services\LineaPedidoCalculada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

class CalculadorLineaPedidoTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private CategoriaProducto $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->create();
        $this->categoria = CategoriaProducto::factory()->for($this->restaurante)->create();
    }

    private function calculador(): CalculadorLineaPedido
    {
        return app(CalculadorLineaPedido::class);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function producto(array $atributos = []): Producto
    {
        return Producto::factory()->for($this->categoria, 'categoria')->create(['precio' => 20.00, ...$atributos]);
    }

    private function productoConVariantes(): Producto
    {
        $producto = $this->producto(['tipo' => 'variantes', 'nombre' => 'Chaufa']);
        ProductoVariante::factory()->for($producto)->create(['nombre' => 'Personal', 'precio' => 22.00]);
        ProductoVariante::factory()->for($producto)->create(['nombre' => 'Familiar', 'precio' => 44.00]);

        return $producto;
    }

    private function asignar(Producto $producto, GrupoOpcion $grupo, int $orden = 0): void
    {
        $producto->gruposOpcion()->attach($grupo->id, ['orden' => $orden]);
    }

    /**
     * @param  array<string, float>  $opciones  nombre => precio extra
     * @return array{0: GrupoOpcion, 1: array<string, Opcion>}
     */
    private function grupoCon(array $opciones, ?GrupoOpcion $grupo = null): array
    {
        $grupo ??= GrupoOpcion::factory()->for($this->restaurante)->create();
        $creadas = [];
        $orden = 0;

        foreach ($opciones as $nombre => $precioExtra) {
            $creadas[$nombre] = Opcion::factory()->for($grupo, 'grupo')->create(['nombre' => $nombre, 'precio_extra' => $precioExtra, 'orden' => $orden++]);
        }

        return [$grupo, $creadas];
    }

    /**
     * @param  array<int, int|string>  $opcionIds
     */
    private function assertRechaza(string $clave, string $mensaje, int $productoId, ?int $varianteId = null, array $opcionIds = [], int $cantidad = 1): void
    {
        try {
            $this->calculador()->calcular($productoId, $varianteId, $opcionIds, $cantidad);
        } catch (ValidationException $e) {
            $this->assertSame($mensaje, $e->errors()[$clave][0] ?? null);

            return;
        }

        $this->fail("Se esperaba un error de validación en {$clave}.");
    }

    public function test_simple_product_uses_its_price_and_multiplies_by_quantity(): void
    {
        $producto = $this->producto(['precio' => 18.50, 'nombre' => 'Anticuchos']);

        $linea = $this->calculador()->calcular($producto->id, null, [], 3);

        $this->assertInstanceOf(LineaPedidoCalculada::class, $linea);
        $this->assertSame($producto->id, $linea->productoId);
        $this->assertSame('Anticuchos', $linea->nombre);
        $this->assertSame(3, $linea->cantidad);
        $this->assertSame('18.50', $linea->precioUnitario);
        $this->assertSame('55.50', $linea->subtotal);
        $this->assertNull($linea->varianteId);
        $this->assertSame([], $linea->opciones);
    }

    public function test_variant_price_replaces_the_product_price(): void
    {
        $producto = $this->productoConVariantes();
        $familiar = $producto->variantes()->where('nombre', 'Familiar')->first();

        $linea = $this->calculador()->calcular($producto->id, $familiar->id, [], 2);

        $this->assertSame($familiar->id, $linea->varianteId);
        $this->assertSame('Familiar', $linea->varianteNombre);
        $this->assertSame('44.00', $linea->precioUnitario);
        $this->assertSame('88.00', $linea->subtotal);
    }

    public function test_extras_are_added_to_the_variant_price_and_copied_in_menu_order(): void
    {
        $producto = $this->productoConVariantes();
        $personal = $producto->variantes()->where('nombre', 'Personal')->first();
        [$adicionales, $extras] = $this->grupoCon(['Piña' => 3.00, 'Tocino' => 5.00], GrupoOpcion::factory()->for($this->restaurante)->multiple()->create(['nombre' => 'Adicionales']));
        [$guarnicion, $lados] = $this->grupoCon(['Papas' => 0.00], GrupoOpcion::factory()->for($this->restaurante)->create(['nombre' => 'Guarnición']));
        $this->asignar($producto, $guarnicion, 0);
        $this->asignar($producto, $adicionales, 1);

        $linea = $this->calculador()->calcular($producto->id, $personal->id, [$extras['Tocino']->id, $extras['Piña']->id, $lados['Papas']->id], 2);

        $this->assertSame('30.00', $linea->precioUnitario);
        $this->assertSame('60.00', $linea->subtotal);
        $this->assertSame([
            ['opcion_id' => $lados['Papas']->id, 'grupo_nombre' => 'Guarnición', 'nombre' => 'Papas', 'precio_extra' => '0.00'],
            ['opcion_id' => $extras['Piña']->id, 'grupo_nombre' => 'Adicionales', 'nombre' => 'Piña', 'precio_extra' => '3.00'],
            ['opcion_id' => $extras['Tocino']->id, 'grupo_nombre' => 'Adicionales', 'nombre' => 'Tocino', 'precio_extra' => '5.00'],
        ], $linea->opciones);
    }

    public function test_cents_do_not_accumulate_rounding_errors(): void
    {
        $producto = $this->producto(['precio' => 0.10]);
        [$grupo, $opciones] = $this->grupoCon(['A' => 0.20], GrupoOpcion::factory()->for($this->restaurante)->multiple()->create());
        $this->asignar($producto, $grupo);

        $linea = $this->calculador()->calcular($producto->id, null, [$opciones['A']->id], 3);

        $this->assertSame('0.30', $linea->precioUnitario);
        $this->assertSame('0.90', $linea->subtotal);
    }

    public function test_combo_uses_its_fixed_price_with_internal_choices_at_no_cost(): void
    {
        $combo = $this->producto(['tipo' => 'combo', 'personas' => 5, 'precio' => 129.00]);
        [$grupo, $opciones] = $this->grupoCon(['Pollo' => 0.00, 'Chancho' => 0.00], GrupoOpcion::factory()->for($this->restaurante)->obligatorio()->create(['nombre' => 'Chaufa de']));
        $this->asignar($combo, $grupo);

        $linea = $this->calculador()->calcular($combo->id, null, [$opciones['Chancho']->id], 1);

        $this->assertSame('129.00', $linea->precioUnitario);
        $this->assertSame('Chancho', $linea->opciones[0]['nombre']);

        $this->assertRechaza("grupos.{$grupo->id}", 'Elige al menos 1 opción en «Chaufa de».', $combo->id);
    }

    public function test_option_ids_may_arrive_as_numeric_strings_from_a_request(): void
    {
        $producto = $this->producto();
        [$grupo, $opciones] = $this->grupoCon(['A' => 2.00]);
        $this->asignar($producto, $grupo);

        $linea = $this->calculador()->calcular($producto->id, null, [(string) $opciones['A']->id], 1);

        $this->assertSame('22.00', $linea->precioUnitario);
    }

    public function test_the_calculator_only_accepts_ids_and_quantity(): void
    {
        $parametros = (new ReflectionMethod(CalculadorLineaPedido::class, 'calcular'))->getParameters();

        $firma = array_map(function ($parametro): string {
            $tipo = $parametro->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $tipo);

            return ($tipo->allowsNull() ? '?' : '').$tipo->getName().' '.$parametro->getName();
        }, $parametros);

        $this->assertSame(['int productoId', '?int varianteId', 'array opcionIds', 'int cantidad'], $firma);
    }

    public function test_structures_with_client_prices_are_rejected_instead_of_read(): void
    {
        $producto = $this->producto();
        [$grupo, $opciones] = $this->grupoCon(['Tocino' => 5.00], GrupoOpcion::factory()->for($this->restaurante)->multiple()->create());
        $this->asignar($producto, $grupo);

        $this->assertRechaza('opciones', 'Las opciones elegidas no son válidas.', $producto->id, opcionIds: [
            ['id' => $opciones['Tocino']->id, 'precio_extra' => 0],
        ]);
        $this->assertRechaza('opciones', 'Las opciones elegidas no son válidas.', $producto->id, opcionIds: ['5.00']);
    }

    public function test_prices_always_come_from_the_database(): void
    {
        $producto = $this->productoConVariantes();
        $personal = $producto->variantes()->where('nombre', 'Personal')->first();
        [$grupo, $opciones] = $this->grupoCon(['Tocino' => 5.00], GrupoOpcion::factory()->for($this->restaurante)->multiple()->create());
        $this->asignar($producto, $grupo);

        // Las copias en memoria se alteran sin guardar: el cálculo no debe verlas.
        $personal->precio = 0.01;
        $opciones['Tocino']->precio_extra = 0.01;

        $linea = $this->calculador()->calcular($producto->id, $personal->id, [$opciones['Tocino']->id], 1);
        $this->assertSame('27.00', $linea->precioUnitario);

        // Un cambio guardado en la base sí se refleja de inmediato.
        ProductoVariante::whereKey($personal->id)->update(['precio' => 30.00]);
        Opcion::whereKey($opciones['Tocino']->id)->update(['precio_extra' => 6.50]);

        $linea = $this->calculador()->calcular($producto->id, $personal->id, [$opciones['Tocino']->id], 1);
        $this->assertSame('36.50', $linea->precioUnitario);
    }

    public function test_quantity_must_be_within_the_business_limit(): void
    {
        $producto = $this->producto(['precio' => 10.00]);

        $this->assertSame(50, CalculadorLineaPedido::CANTIDAD_MAXIMA);

        $linea = $this->calculador()->calcular($producto->id, null, [], CalculadorLineaPedido::CANTIDAD_MAXIMA);
        $this->assertSame('500.00', $linea->subtotal);

        $this->assertRechaza('cantidad', 'La cantidad debe ser al menos 1.', $producto->id, cantidad: 0);
        $this->assertRechaza('cantidad', 'La cantidad debe ser al menos 1.', $producto->id, cantidad: -2);
        $this->assertRechaza('cantidad', 'La cantidad máxima por línea es 50.', $producto->id, cantidad: 51);
    }

    public function test_unit_price_that_does_not_fit_the_order_column_is_rejected(): void
    {
        $producto = $this->producto(['precio' => 9999.99]);
        $grupo = GrupoOpcion::factory()->for($this->restaurante)->multiple()->create();
        $ids = Opcion::factory()->count(100)->for($grupo, 'grupo')->create(['precio_extra' => 9999.99])->modelKeys();
        $this->asignar($producto, $grupo);

        $this->assertRechaza('opciones', 'El precio de esta línea supera el máximo permitido.', $producto->id, opcionIds: $ids);
    }

    public function test_variant_is_required_on_products_with_variants(): void
    {
        $this->assertRechaza('variante_id', 'Elige una variante de «Chaufa».', $this->productoConVariantes()->id);
    }

    public function test_product_without_variants_rejects_a_variant(): void
    {
        $producto = $this->producto(['nombre' => 'Anticuchos']);
        $ajena = ProductoVariante::factory()->for($this->productoConVariantes())->create();

        $this->assertRechaza('variante_id', '«Anticuchos» no tiene variantes.', $producto->id, $ajena->id);
    }

    public function test_variant_of_another_product_is_rejected(): void
    {
        $producto = $this->productoConVariantes();
        $ajena = ProductoVariante::factory()->for($this->productoConVariantes())->create();

        $this->assertRechaza('variante_id', 'La variante elegida no pertenece a «Chaufa».', $producto->id, $ajena->id);
    }

    public function test_sold_out_variant_is_rejected(): void
    {
        $producto = $this->productoConVariantes();
        $agotada = ProductoVariante::factory()->agotada()->for($producto)->create(['nombre' => 'Mediano']);

        $this->assertRechaza('variante_id', 'La variante «Mediano» no está disponible.', $producto->id, $agotada->id);
    }

    public function test_sold_out_or_removed_products_are_rejected(): void
    {
        $agotado = $this->producto(['nombre' => 'Parrilla', 'disponible' => false]);
        $eliminado = $this->producto();
        $eliminado->delete();

        $this->assertRechaza('producto_id', '«Parrilla» no está disponible.', $agotado->id);
        $this->assertRechaza('producto_id', 'Este producto ya no está en la carta.', $eliminado->id);
        $this->assertRechaza('producto_id', 'Este producto ya no está en la carta.', 999999);
    }

    public function test_products_of_hidden_categories_are_rejected(): void
    {
        $inactiva = CategoriaProducto::factory()->for($this->restaurante)->create(['activa' => false]);
        $padreInactivo = CategoriaProducto::factory()->for($this->restaurante)->create(['activa' => false]);
        $hija = CategoriaProducto::factory()->hijaDe($padreInactivo)->create();

        $enInactiva = Producto::factory()->for($inactiva, 'categoria')->create(['nombre' => 'Uno']);
        $enHija = Producto::factory()->for($hija, 'categoria')->create(['nombre' => 'Dos']);

        $this->assertRechaza('producto_id', '«Uno» no se muestra en la carta.', $enInactiva->id);
        $this->assertRechaza('producto_id', '«Dos» no se muestra en la carta.', $enHija->id);
    }

    public function test_required_group_needs_a_choice(): void
    {
        $producto = $this->producto();
        [$grupo] = $this->grupoCon(['Papas' => 0.00], GrupoOpcion::factory()->for($this->restaurante)->obligatorio()->create(['nombre' => 'Guarnición']));
        $this->asignar($producto, $grupo);

        $this->assertRechaza("grupos.{$grupo->id}", 'Elige al menos 1 opción en «Guarnición».', $producto->id);
    }

    public function test_optional_group_can_be_skipped(): void
    {
        $producto = $this->producto();
        [$grupo] = $this->grupoCon(['Tocino' => 5.00], GrupoOpcion::factory()->for($this->restaurante)->multiple()->create());
        $this->asignar($producto, $grupo);

        $this->assertSame('20.00', $this->calculador()->calcular($producto->id, null, [], 1)->precioUnitario);
    }

    public function test_multiple_group_enforces_its_minimum_and_maximum(): void
    {
        $producto = $this->producto();
        [$grupo, $o] = $this->grupoCon(['A' => 1.00, 'B' => 1.00, 'C' => 1.00, 'D' => 1.00], GrupoOpcion::factory()->for($this->restaurante)->multiple(2, 3)->create(['nombre' => 'Salsas']));
        $this->asignar($producto, $grupo);

        $this->assertRechaza("grupos.{$grupo->id}", 'Elige al menos 2 opciones en «Salsas».', $producto->id, opcionIds: [$o['A']->id]);
        $this->assertRechaza("grupos.{$grupo->id}", 'Elige como máximo 3 opciones en «Salsas».', $producto->id, opcionIds: [$o['A']->id, $o['B']->id, $o['C']->id, $o['D']->id]);

        $linea = $this->calculador()->calcular($producto->id, null, [$o['A']->id, $o['B']->id, $o['C']->id], 1);
        $this->assertSame('23.00', $linea->precioUnitario);
    }

    public function test_single_choice_group_accepts_only_one_option(): void
    {
        $producto = $this->producto();
        [$grupo, $o] = $this->grupoCon(['A' => 0.00, 'B' => 0.00], GrupoOpcion::factory()->for($this->restaurante)->create(['nombre' => 'Sabor']));
        $this->asignar($producto, $grupo);

        $this->assertRechaza("grupos.{$grupo->id}", 'Elige como máximo 1 opción en «Sabor».', $producto->id, opcionIds: [$o['A']->id, $o['B']->id]);
    }

    public function test_sold_out_option_is_rejected(): void
    {
        $producto = $this->producto();
        [$grupo, $o] = $this->grupoCon(['Papas' => 0.00]);
        $agotada = Opcion::factory()->agotada()->for($grupo, 'grupo')->create(['nombre' => 'Arroz']);
        $this->asignar($producto, $grupo);

        $this->assertRechaza("grupos.{$grupo->id}", '«Arroz» no está disponible.', $producto->id, opcionIds: [$agotada->id]);
        $this->assertSame('20.00', $this->calculador()->calcular($producto->id, null, [$o['Papas']->id], 1)->precioUnitario);
    }

    public function test_required_group_with_everything_sold_out_explains_the_problem(): void
    {
        $producto = $this->producto();
        $grupo = GrupoOpcion::factory()->for($this->restaurante)->obligatorio()->create(['nombre' => 'Guarnición']);
        Opcion::factory()->agotada()->for($grupo, 'grupo')->create();
        $this->asignar($producto, $grupo);

        $this->assertRechaza("grupos.{$grupo->id}", '«Guarnición» no tiene suficientes opciones disponibles.', $producto->id);
    }

    public function test_repeated_option_is_rejected(): void
    {
        $producto = $this->producto();
        [$grupo, $o] = $this->grupoCon(['Tocino' => 5.00], GrupoOpcion::factory()->for($this->restaurante)->multiple()->create());
        $this->asignar($producto, $grupo);

        $this->assertRechaza('opciones', 'Hay una opción repetida.', $producto->id, opcionIds: [$o['Tocino']->id, $o['Tocino']->id]);
    }

    public function test_option_of_a_group_not_assigned_to_the_product_is_rejected(): void
    {
        $producto = $this->producto(['nombre' => 'Anticuchos']);
        [, $o] = $this->grupoCon(['Tocino' => 5.00]);

        $this->assertRechaza('opciones', 'Una de las opciones elegidas no pertenece a «Anticuchos».', $producto->id, opcionIds: [$o['Tocino']->id]);
    }

    public function test_inactive_group_is_neither_required_nor_selectable(): void
    {
        $producto = $this->producto(['nombre' => 'Anticuchos']);
        [$grupo, $o] = $this->grupoCon(['Papas' => 0.00], GrupoOpcion::factory()->for($this->restaurante)->obligatorio()->inactivo()->create());
        $this->asignar($producto, $grupo);

        $this->assertSame('20.00', $this->calculador()->calcular($producto->id, null, [], 1)->precioUnitario);
        $this->assertRechaza('opciones', 'Una de las opciones elegidas no pertenece a «Anticuchos».', $producto->id, opcionIds: [$o['Papas']->id]);
    }

    public function test_all_errors_are_reported_together(): void
    {
        $producto = $this->productoConVariantes();
        [$grupo] = $this->grupoCon(['Papas' => 0.00], GrupoOpcion::factory()->for($this->restaurante)->obligatorio()->create());
        $this->asignar($producto, $grupo);

        try {
            $this->calculador()->calcular($producto->id, null, [], 0);
            $this->fail('Se esperaba un error de validación.');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['cantidad', 'variante_id', "grupos.{$grupo->id}"], array_keys($e->errors()));
        }
    }

    public function test_a_group_from_another_restaurant_never_counts_even_if_linked(): void
    {
        $producto = $this->producto(['nombre' => 'Anticuchos']);
        $otroLocal = Restaurante::factory()->create();
        [$ajeno, $o] = $this->grupoCon(['Foránea' => 1.00], GrupoOpcion::factory()->for($otroLocal)->obligatorio()->create());
        $this->asignar($producto, $ajeno);

        $this->assertSame('20.00', $this->calculador()->calcular($producto->id, null, [], 1)->precioUnitario);
        $this->assertRechaza('opciones', 'Una de las opciones elegidas no pertenece a «Anticuchos».', $producto->id, opcionIds: [$o['Foránea']->id]);
    }

    public function test_storefront_customer_gets_the_same_validation_as_a_guest(): void
    {
        $this->actingAs(User::factory()->cliente()->create());

        $producto = $this->producto();
        $otroLocal = Restaurante::factory()->create();
        [$ajeno, $o] = $this->grupoCon(['Foránea' => 1.00], GrupoOpcion::factory()->for($otroLocal)->multiple()->create());
        $this->asignar($producto, $ajeno);

        $this->assertSame('20.00', $this->calculador()->calcular($producto->id, null, [], 1)->precioUnitario);
        $this->assertRechaza('opciones', "Una de las opciones elegidas no pertenece a «{$producto->nombre}».", $producto->id, opcionIds: [$o['Foránea']->id]);
    }

    public function test_staff_of_another_restaurant_cannot_price_a_foreign_product(): void
    {
        $producto = $this->producto();
        $this->actingAs(User::factory()->admin(Restaurante::factory()->create())->create());

        $this->assertRechaza('producto_id', 'Este producto ya no está en la carta.', $producto->id);
    }

    public function test_own_staff_prices_products_of_their_restaurant(): void
    {
        $producto = $this->producto();
        [$grupo, $o] = $this->grupoCon(['Tocino' => 5.00], GrupoOpcion::factory()->for($this->restaurante)->multiple()->create());
        $this->asignar($producto, $grupo);

        $this->actingAs(User::factory()->cajero($this->restaurante)->create());

        $this->assertSame('25.00', $this->calculador()->calcular($producto->id, null, [$o['Tocino']->id], 1)->precioUnitario);
    }
}
