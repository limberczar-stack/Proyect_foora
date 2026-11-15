<?php

namespace Tests\Feature\Vitrina;

use App\Models\CategoriaProducto;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Restaurante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * nombre_cliente, telefono_cliente y metodo_pago_id dejan de ser obligatorios en la base para
 * los pedidos de salón. Un pedido de la vitrina los sigue exigiendo, y la vitrina nunca crea
 * un pedido de salón.
 */
class PedidoPublicoCamposTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private Producto $producto;

    private MetodoPago $efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->conModulos('mesas')->create(['slug' => 'polleria-central', 'pedido_minimo' => 0]);
        $categoria = CategoriaProducto::factory()->for($this->restaurante)->create();
        $this->producto = Producto::factory()->for($categoria, 'categoria')->create(['precio' => 18.00]);
        $this->efectivo = MetodoPago::create(['codigo' => 'efectivo', 'nombre' => 'Efectivo']);
        $this->restaurante->metodosPago()->attach($this->efectivo, ['activo' => true]);
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Ana Torres',
            'telefono' => '987654321',
            'tipo' => 'recojo',
            'direccion' => null,
            'referencia' => null,
            'metodo_pago_id' => $this->efectivo->id,
            'referencia_pago' => null,
            'acepta_privacidad' => true,
            'sitio_web' => '',
            'idempotency_key' => Str::uuid()->toString(),
            'lineas' => [['producto_id' => $this->producto->id, 'variante_id' => null, 'opciones' => [], 'cantidad' => 1, 'observacion' => null]],
        ], $cambios);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function camposObligatorios(): array
    {
        return [
            'nombre vacío' => ['nombre', ''],
            'sin nombre' => ['nombre', null],
            'teléfono vacío' => ['telefono', ''],
            'sin teléfono' => ['telefono', null],
            'sin método de pago' => ['metodo_pago_id', null],
            'método de pago vacío' => ['metodo_pago_id', ''],
        ];
    }

    #[DataProvider('camposObligatorios')]
    public function test_a_public_order_still_requires_name_phone_and_payment_method(string $campo, mixed $valor): void
    {
        $this->postJson(route('vitrina.pedido.store', 'polleria-central'), $this->datos([$campo => $valor]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);

        $this->assertSame(0, Pedido::count());
    }

    public function test_a_public_order_without_those_keys_at_all_is_rejected(): void
    {
        $datos = $this->datos();
        unset($datos['nombre'], $datos['telefono'], $datos['metodo_pago_id']);

        $this->postJson(route('vitrina.pedido.store', 'polleria-central'), $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'telefono', 'metodo_pago_id']);

        $this->assertSame(0, Pedido::count());
    }

    public function test_the_public_storefront_never_creates_a_salon_order(): void
    {
        $this->postJson(route('vitrina.pedido.store', 'polleria-central'), $this->datos(['tipo' => 'salon']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tipo');

        $this->assertSame(0, Pedido::count());
    }

    public function test_a_valid_public_order_stores_the_three_fields(): void
    {
        $this->postJson(route('vitrina.pedido.store', 'polleria-central'), $this->datos())->assertCreated();

        $pedido = Pedido::sole();
        $this->assertSame(['Ana Torres', '987654321', $this->efectivo->id, 'recojo', null], [
            $pedido->nombre_cliente, $pedido->telefono_cliente, $pedido->metodo_pago_id, $pedido->tipo, $pedido->cuenta_id ?? null,
        ]);
    }
}
