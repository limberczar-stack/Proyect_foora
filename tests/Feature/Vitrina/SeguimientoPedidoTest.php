<?php

namespace Tests\Feature\Vitrina;

use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoItemOpcion;
use App\Models\Restaurante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeguimientoPedidoTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->create([
            'slug' => 'polleria-central',
            'nombre' => 'Pollería Central',
            'telefono' => '962 345 678',
        ]);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function pedido(array $atributos = []): Pedido
    {
        return Pedido::factory()->delivery()->create([
            'restaurante_id' => $this->restaurante->id,
            'numero' => 12,
            'nombre_cliente' => 'Ana María Torres',
            'telefono_cliente' => '987654321',
            ...$atributos,
        ]);
    }

    private function url(Pedido $pedido): string
    {
        return route('vitrina.pedido.seguimiento', ['polleria-central', $pedido->codigo_seguimiento]);
    }

    private function urlEstado(Pedido $pedido): string
    {
        return route('vitrina.pedido.estado', ['polleria-central', $pedido->codigo_seguimiento]);
    }

    public function test_valid_code_shows_the_order_summary_and_timeline(): void
    {
        $pedido = $this->pedido(['subtotal' => 30, 'costo_delivery' => 5, 'total' => 35]);
        $item = PedidoItem::create(['pedido_id' => $pedido->id, 'nombre' => 'Chaufa', 'variante_nombre' => 'Personal', 'cantidad' => 2, 'precio_unitario' => 15, 'subtotal' => 30]);
        PedidoItemOpcion::create(['pedido_item_id' => $item->id, 'grupo_nombre' => 'Adicionales', 'nombre' => 'Tocino', 'precio_extra' => 0]);

        $this->get($this->url($pedido))
            ->assertOk()
            ->assertSee('Pedido #12')
            ->assertSee('Hola, Ana.')
            ->assertSee('Delivery a domicilio')
            ->assertSee('Chaufa')
            ->assertSee('Personal · Tocino')
            ->assertSee('35.00')
            ->assertSeeInOrder(['Recibido', 'En preparación', 'Listo', 'En camino', 'Entregado']);
    }

    public function test_pickup_timeline_says_ready_to_collect_and_has_no_delivery_step(): void
    {
        $pedido = Pedido::factory()->create(['restaurante_id' => $this->restaurante->id]);

        $this->get($this->url($pedido))
            ->assertSee('Recojo en local')
            ->assertSee('Para recoger')
            ->assertDontSee('En camino');
    }

    public function test_tracking_does_not_leak_phone_or_address_or_full_name(): void
    {
        $pedido = $this->pedido();

        $pagina = $this->get($this->url($pedido))->assertOk();

        $pagina->assertDontSee('987654321')
            ->assertDontSee('Huallayco')
            ->assertDontSee('Casa azul')
            ->assertDontSee('Torres');

        $json = $this->getJson($this->urlEstado($pedido))->assertOk()->getContent();

        foreach (['987654321', 'Huallayco', 'Casa azul', 'Torres', 'Ana'] as $dato) {
            $this->assertStringNotContainsString($dato, $json);
        }
    }

    public function test_tracking_page_is_private(): void
    {
        $respuesta = $this->get($this->url($this->pedido()));

        $respuesta->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertSee('name="robots" content="noindex, nofollow"', false);
        $this->assertStringContainsString('no-store', $respuesta->headers->get('Cache-Control'));
        $this->assertCount(0, $respuesta->headers->getCookies());
    }

    public function test_code_from_another_restaurant_is_not_found(): void
    {
        $otro = Restaurante::factory()->create(['slug' => 'otro-local']);
        $ajeno = Pedido::factory()->create(['restaurante_id' => $otro->id, 'nombre_cliente' => 'Luis Ajeno']);

        $this->get(route('vitrina.pedido.seguimiento', ['polleria-central', $ajeno->codigo_seguimiento]))
            ->assertNotFound()
            ->assertSee('No encontramos este pedido')
            ->assertDontSee('Luis');
        $this->getJson(route('vitrina.pedido.estado', ['polleria-central', $ajeno->codigo_seguimiento]))->assertNotFound();
    }

    public function test_unknown_code_or_restaurant_is_not_found(): void
    {
        $pedido = $this->pedido();

        $this->get(route('vitrina.pedido.seguimiento', ['polleria-central', 'codigoInventado']))
            ->assertNotFound()
            ->assertSee('No encontramos este pedido');
        $this->get(route('vitrina.pedido.seguimiento', ['no-existe', $pedido->codigo_seguimiento]))->assertNotFound();
    }

    public function test_order_id_or_number_does_not_work_as_a_code(): void
    {
        $pedido = $this->pedido();

        $this->get(route('vitrina.pedido.seguimiento', ['polleria-central', $pedido->id]))->assertNotFound();
        $this->get(route('vitrina.pedido.seguimiento', ['polleria-central', $pedido->numero]))->assertNotFound();
    }

    public function test_tracking_survives_when_the_restaurant_is_hidden_later(): void
    {
        $pedido = $this->pedido();
        $this->restaurante->update(['visible_en_app' => false]);

        $this->get($this->url($pedido))->assertOk();
    }

    public function test_whatsapp_button_links_to_the_restaurant_with_a_prefilled_message(): void
    {
        $this->get($this->url($this->pedido()))
            ->assertSee('https://wa.me/51962345678?text=', false)
            ->assertSee(rawurlencode('Hola, te escribo por mi pedido #12 en Pollería Central.'), false)
            ->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_whatsapp_button_is_hidden_without_a_valid_phone(): void
    {
        $this->restaurante->update(['telefono' => null]);

        $this->get($this->url($this->pedido()))->assertDontSee('wa.me');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function estados(): array
    {
        return [
            'nuevo' => ['nuevo', 'Recibido', ['actual', 'pendiente', 'pendiente', 'pendiente', 'pendiente']],
            'en preparación' => ['en_preparacion', 'En preparación', ['hecho', 'actual', 'pendiente', 'pendiente', 'pendiente']],
            'en camino' => ['en_camino', 'En camino', ['hecho', 'hecho', 'hecho', 'actual', 'pendiente']],
            'entregado' => ['entregado', 'Entregado', ['hecho', 'hecho', 'hecho', 'hecho', 'hecho']],
        ];
    }

    /**
     * @param  list<string>  $pasos
     */
    #[DataProvider('estados')]
    public function test_status_endpoint_reports_each_step(string $estado, string $titulo, array $pasos): void
    {
        $respuesta = $this->getJson($this->urlEstado($this->pedido(['estado' => $estado])))
            ->assertOk()
            ->assertJsonPath('estado', $estado)
            ->assertJsonPath('titulo', $titulo)
            ->assertJsonPath('final', $estado === 'entregado');

        $this->assertSame($pasos, array_column($respuesta->json('pasos'), 'estado'));
    }

    public function test_cancelled_order_shows_the_reason_without_internal_notes(): void
    {
        $pedido = $this->pedido([
            'estado' => 'cancelado',
            'cancelacion_motivo' => 'pago_no_confirmado',
            'cancelacion_detalle' => 'Nota interna del cajero',
        ]);

        $this->get($this->url($pedido))
            ->assertSee('Cancelado')
            ->assertSee('No se pudo confirmar el pago.')
            ->assertDontSee('Nota interna');

        $this->getJson($this->urlEstado($pedido))
            ->assertJsonPath('cancelado', true)
            ->assertJsonPath('final', true)
            ->assertJsonPath('motivo', 'No se pudo confirmar el pago.');
    }

    public function test_payment_status_is_shown(): void
    {
        $this->get($this->url($this->pedido(['estado_pago' => 'por_verificar'])))
            ->assertSee('El local está verificando tu pago.');
    }

    public function test_client_text_is_escaped_in_tracking(): void
    {
        $ataque = '</script><script>alert(1)</script>';
        $pedido = $this->pedido(['nombre_cliente' => '<img/src=x/onerror=alert(2)> Torres', 'direccion_entrega' => $ataque]);
        PedidoItem::create(['pedido_id' => $pedido->id, 'nombre' => $ataque, 'cantidad' => 1, 'precio_unitario' => 1, 'subtotal' => 1, 'observacion' => $ataque]);

        $html = $this->get($this->url($pedido))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img/src=x', $html);
        $this->assertStringContainsString('&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img/src=x/onerror=alert(2)&gt;', $html);
    }

    public function test_order_placed_with_html_stays_as_plain_text_through_the_flow(): void
    {
        $ataque = '</script><b>hola</b>';
        $pedido = $this->pedido(['nombre_cliente' => $ataque]);

        $this->assertSame($ataque, $pedido->fresh()->nombre_cliente);

        $html = $this->get($this->url($pedido))->getContent();

        $this->assertStringNotContainsString('<b>hola', $html);
    }

    public function test_status_endpoint_is_light(): void
    {
        $pedido = $this->pedido();

        DB::enableQueryLog();
        $this->getJson($this->urlEstado($pedido))->assertOk();
        $consultas = collect(DB::getQueryLog())
            ->reject(fn (array $consulta): bool => str_contains($consulta['query'], '"cache'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(2, $consultas);
    }
}
