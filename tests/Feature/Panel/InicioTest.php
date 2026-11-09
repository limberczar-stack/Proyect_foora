<?php

namespace Tests\Feature\Panel;

use App\Models\Alerta;
use App\Models\CategoriaProducto;
use App\Models\HorarioAtencion;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Restaurante;
use App\Models\RestauranteMetodoPago;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InicioTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-08 15:00', 'America/Lima'));

        $this->restaurante = Restaurante::factory()->create(['nombre' => 'Pollería Real']);
        $this->admin = User::factory()->admin($this->restaurante)->create(['name' => 'Rosa Quispe']);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function pedido(array $atributos = [], ?Restaurante $restaurante = null): Pedido
    {
        $pedido = Pedido::factory()->create(['restaurante_id' => ($restaurante ?? $this->restaurante)->id, ...$atributos]);

        if (isset($atributos['created_at'])) {
            $pedido->forceFill(['created_at' => $atributos['created_at']])->saveQuietly();
        }

        return $pedido;
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function alerta(array $atributos = [], ?Restaurante $restaurante = null): Alerta
    {
        return Alerta::create([
            'restaurante_id' => ($restaurante ?? $this->restaurante)->id,
            'tipo' => 'pedido_nuevo',
            'prioridad' => 'media',
            'titulo' => 'Nuevo pedido',
            ...$atributos,
        ]);
    }

    private function inicio(?User $usuario = null): TestResponse
    {
        // Cada petición carga su propio usuario, como en producción: nada queda en caché entre una y otra.
        return $this->actingAs(($usuario ?? $this->admin)->fresh())->get(route('panel.inicio'))->assertOk();
    }

    private function completarCarta(): void
    {
        Producto::factory()->create([
            'restaurante_id' => $this->restaurante->id,
            'categoria_id' => CategoriaProducto::factory()->create(['restaurante_id' => $this->restaurante->id])->id,
        ]);
    }

    private function completarHorario(): void
    {
        HorarioAtencion::create(['restaurante_id' => $this->restaurante->id, 'dia_semana' => 1, 'abre' => '09:00', 'cierra' => '22:00', 'cerrado' => false]);
    }

    private function activarYape(?string $numero): void
    {
        RestauranteMetodoPago::create([
            'restaurante_id' => $this->restaurante->id,
            'metodo_pago_id' => MetodoPago::firstOrCreate(['codigo' => 'yape'], ['nombre' => 'Yape', 'requiere_validacion' => true])->id,
            'activo' => true,
            'numero_cuenta' => $numero,
        ]);
    }

    public function test_greets_the_user_with_todays_date_in_the_restaurant_zone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 23:30', 'America/Lima'));

        $this->inicio()
            ->assertSee('Hola, Rosa')
            ->assertSee('Domingo 8 de noviembre');
    }

    public function test_cards_show_exact_figures_from_real_orders(): void
    {
        $this->pedido(['estado' => 'nuevo', 'total' => 10]);
        $this->pedido(['estado' => 'en_preparacion', 'total' => 20]);
        $this->pedido(['estado' => 'listo', 'total' => 30]);
        $this->pedido(['estado' => 'en_camino', 'tipo' => 'delivery', 'total' => 40]);
        $this->pedido(['estado' => 'entregado', 'entregado_at' => now(), 'total' => '45.10']);
        $this->pedido(['estado' => 'entregado', 'entregado_at' => now(), 'total' => '19.40']);
        $this->pedido(['estado' => 'cancelado', 'cancelado_at' => now(), 'total' => 1000]);

        $vista = $this->inicio()->viewData('tarjetas');

        $this->assertSame(6, $vista['pedidosHoy']);
        $this->assertEqualsWithDelta(64.50, $vista['ventasHoy'], 0.001);
        $this->assertSame(2, $vista['entregadosHoy']);
        $this->assertSame(4, $vista['activos']);
        $this->assertSame(1, $vista['nuevos']);

        $this->inicio()
            ->assertSeeInOrder(['Pedidos de hoy', '6', 'Ventas de hoy', '64.50', 'Pedidos activos', '4', 'Nuevos sin atender', '1'])
            ->assertSee('De 2 entregas')
            ->assertSee('Atender 1 nuevo')
            ->assertSee(route('panel.pedidos.index', ['estado' => 'nuevo']));
    }

    /**
     * Lima es UTC-5: el día del local empieza a las 05:00 UTC.
     *
     * @return array<string, array{0: string, 1: int, 2: float}>
     */
    public static function fronterasDeHoy(): array
    {
        return [
            'el último segundo de ayer no cuenta' => ['2026-11-08 04:59:59', 0, 0.0],
            'el primer segundo de hoy cuenta' => ['2026-11-08 05:00:00', 1, 25.0],
            'el último segundo de hoy cuenta' => ['2026-11-09 04:59:59', 1, 25.0],
            'el primer segundo de mañana no cuenta' => ['2026-11-09 05:00:00', 0, 0.0],
        ];
    }

    #[DataProvider('fronterasDeHoy')]
    public function test_today_is_the_lima_day_at_the_midnight_boundary(string $momentoUtc, int $pedidosHoy, float $ventasHoy): void
    {
        $momento = Carbon::parse($momentoUtc, 'UTC');
        $this->pedido(['estado' => 'entregado', 'total' => 25, 'created_at' => $momento, 'entregado_at' => $momento]);

        // Las 23:30 en Lima ya son las 04:30 del día siguiente en UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-08 23:30', 'America/Lima'));

        $tarjetas = $this->inicio()->viewData('tarjetas');

        $this->assertSame($pedidosHoy, $tarjetas['pedidosHoy']);
        $this->assertEqualsWithDelta($ventasHoy, $tarjetas['ventasHoy'], 0.001);
    }

    public function test_sales_count_on_the_day_of_delivery_and_active_orders_ignore_the_date(): void
    {
        $this->pedido([
            'estado' => 'entregado',
            'total' => 50,
            'created_at' => Carbon::parse('2026-11-07 23:50', 'America/Lima')->utc(),
            'entregado_at' => Carbon::parse('2026-11-08 00:10', 'America/Lima')->utc(),
        ]);
        $this->pedido(['estado' => 'en_preparacion', 'total' => 15, 'created_at' => now()->subDays(2)]);

        $tarjetas = $this->inicio()->viewData('tarjetas');

        $this->assertSame(0, $tarjetas['pedidosHoy']);
        $this->assertEqualsWithDelta(50.0, $tarjetas['ventasHoy'], 0.001);
        $this->assertSame(1, $tarjetas['activos']);
    }

    public function test_recent_orders_are_the_latest_five_with_a_link_to_their_detail(): void
    {
        $pedidos = collect(range(1, 7))->map(fn (int $numero): Pedido => $this->pedido([
            'numero' => $numero,
            'nombre_cliente' => "Cliente {$numero}",
            'created_at' => now()->subMinutes(10 - $numero),
        ]));

        $respuesta = $this->inicio();

        $this->assertSame([7, 6, 5, 4, 3], $respuesta->viewData('recientes')->pluck('numero')->all());

        $respuesta
            ->assertSee(route('panel.pedidos.show', $pedidos[6]), false)
            ->assertSee('Cliente 3')
            ->assertDontSee('Cliente 2');
    }

    public function test_recent_order_link_opens_it_in_the_orders_panel(): void
    {
        $pedido = $this->pedido(['estado' => 'listo']);

        $this->inicio();

        $this->get(route('panel.pedidos.show', $pedido))
            ->assertRedirect(route('panel.pedidos.index', ['estado' => 'listo', 'pedido' => $pedido->id]));
    }

    public function test_alerts_show_up_to_five_unresolved_by_priority_with_their_action(): void
    {
        $pedido = $this->pedido();
        $this->alerta(['titulo' => 'Resuelta', 'prioridad' => 'urgente', 'resuelta_at' => now()]);
        $this->alerta(['titulo' => 'Informativa', 'prioridad' => 'informativa']);

        foreach (range(1, 4) as $numero) {
            $this->alerta(['titulo' => "Media {$numero}", 'referencia_tipo' => 'pedido', 'referencia_id' => $pedido->id]);
        }

        $this->alerta(['titulo' => 'Pago por validar', 'tipo' => 'pago_pendiente', 'prioridad' => 'urgente', 'referencia_tipo' => 'pedido', 'referencia_id' => $pedido->id]);

        $respuesta = $this->inicio();

        $this->assertSame(['Pago por validar', 'Media 1', 'Media 2', 'Media 3', 'Media 4'], array_column($respuesta->viewData('alertas'), 'titulo'));
        $this->assertSame(6, $respuesta->viewData('alertasTotal'));

        $respuesta
            ->assertSee('1 alerta urgente por atender')
            ->assertSee('Las 5 más importantes de 6')
            ->assertSee('Revisar pago')
            ->assertSee('Ver pedido')
            ->assertSee(route('panel.pedidos.show', $pedido), false)
            ->assertDontSee('Resuelta');
    }

    public function test_alert_without_a_related_order_has_no_action(): void
    {
        $this->alerta(['titulo' => 'Aviso suelto', 'prioridad' => 'informativa']);

        $this->inicio()->assertSee('Aviso suelto')->assertDontSee('Ver pedido');
    }

    public function test_data_of_other_restaurants_never_shows(): void
    {
        $otro = Restaurante::factory()->create();
        $pedidoAjeno = $this->pedido(['nombre_cliente' => 'Cliente Ajeno', 'estado' => 'entregado', 'entregado_at' => now(), 'total' => 999], $otro);
        $this->pedido(['nombre_cliente' => 'Otro Nuevo', 'estado' => 'nuevo'], $otro);
        $this->alerta(['titulo' => 'Alerta ajena', 'prioridad' => 'urgente'], $otro);
        Producto::factory()->create(['restaurante_id' => $otro->id, 'categoria_id' => CategoriaProducto::factory()->create(['restaurante_id' => $otro->id])->id]);
        HorarioAtencion::create(['restaurante_id' => $otro->id, 'dia_semana' => 1, 'abre' => '09:00', 'cierra' => '22:00', 'cerrado' => false]);

        $this->pedido(['nombre_cliente' => 'Cliente Propio']);

        $respuesta = $this->inicio();
        $tarjetas = $respuesta->viewData('tarjetas');

        $this->assertSame(1, $tarjetas['pedidosHoy']);
        $this->assertSame(0.0, $tarjetas['ventasHoy']);
        $this->assertSame(1, $tarjetas['activos']);
        $this->assertSame(0, $respuesta->viewData('alertasTotal'));
        $this->assertFalse($respuesta->viewData('primerosPasos')['pasos']['carta']['completo']);
        $this->assertFalse($respuesta->viewData('primerosPasos')['pasos']['horario']['completo']);

        $respuesta
            ->assertSee('Cliente Propio')
            ->assertDontSee('Cliente Ajeno')
            ->assertDontSee('Alerta ajena')
            ->assertDontSee(route('panel.pedidos.show', $pedidoAjeno), false);
    }

    public function test_cashier_sees_the_summary_but_not_the_first_steps(): void
    {
        $this->pedido(['nombre_cliente' => 'Cliente Visible']);

        $this->inicio(User::factory()->cajero($this->restaurante)->create(['name' => 'Miguel Torres']))
            ->assertSee('Hola, Miguel')
            ->assertSee('Cliente Visible')
            ->assertDontSee('Primeros pasos');
    }

    public function test_kitchen_is_redirected_to_its_orders(): void
    {
        $cocina = User::factory()->personal('cocina', $this->restaurante)->create();

        $this->actingAs($cocina)->get(route('panel.inicio'))->assertRedirect(route('panel.pedidos.index'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesSinModulos(): array
    {
        return ['mesero' => ['mesero'], 'repartidor' => ['repartidor']];
    }

    #[DataProvider('rolesSinModulos')]
    public function test_roles_without_modules_get_a_clear_page_without_restaurant_data(string $rol): void
    {
        $this->pedido(['nombre_cliente' => 'Cliente Privado']);
        $usuario = User::factory()->personal($rol, $this->restaurante)->create();

        $this->inicio($usuario)
            ->assertSee('Tu cuenta aún no tiene módulos habilitados')
            ->assertSee('Pide al administrador de tu local')
            ->assertSee(route('panel.mi-cuenta'))
            ->assertSee('Cerrar sesión')
            ->assertDontSee('Cliente Privado')
            ->assertDontSee('Ventas de hoy');

        $this->get(route('panel.pedidos.index'))->assertForbidden();
    }

    public function test_first_steps_are_incomplete_for_a_new_restaurant_and_show_empty_states(): void
    {
        $respuesta = $this->inicio();
        $pasos = $respuesta->viewData('primerosPasos');

        $this->assertSame(0, $pasos['completos']);

        $respuesta
            ->assertSee('Primeros pasos')
            ->assertSee('0 de 4 completos')
            ->assertSee(route('panel.carta.productos.create'))
            ->assertSee(route('panel.configuracion.horario'))
            ->assertSee(route('panel.configuracion.pago'))
            ->assertSee('Copiar enlace')
            ->assertSee('data-copiar="'.$this->restaurante->urlVitrina().'"', false)
            ->assertSee('Todavía no recibes pedidos')
            ->assertSee('Sin alertas por ahora')
            ->assertSee('Aquí verás cuántos recibes al día')
            ->assertDontSee('data-reciente=', false);
    }

    public function test_each_first_step_depends_on_its_own_data(): void
    {
        $this->completarCarta();
        $this->assertSame(1, $this->inicio()->viewData('primerosPasos')['completos']);

        HorarioAtencion::create(['restaurante_id' => $this->restaurante->id, 'dia_semana' => 2, 'cerrado' => true]);
        $this->assertFalse($this->inicio()->viewData('primerosPasos')['pasos']['horario']['completo']);

        $this->completarHorario();
        $this->assertTrue($this->inicio()->viewData('primerosPasos')['pasos']['horario']['completo']);

        $this->activarYape(null);
        $this->assertFalse($this->inicio()->viewData('primerosPasos')['pasos']['pago']['completo']);

        RestauranteMetodoPago::where('restaurante_id', $this->restaurante->id)->update(['numero_cuenta' => '987654321']);
        $this->assertTrue($this->inicio()->viewData('primerosPasos')['pasos']['pago']['completo']);

        $this->assertFalse($this->inicio()->viewData('primerosPasos')['pasos']['enlace']['completo']);
    }

    public function test_cash_counts_as_a_usable_payment_method_without_any_data(): void
    {
        RestauranteMetodoPago::create([
            'restaurante_id' => $this->restaurante->id,
            'metodo_pago_id' => MetodoPago::firstOrCreate(['codigo' => 'efectivo'], ['nombre' => 'Efectivo'])->id,
            'activo' => true,
        ]);

        $this->assertTrue($this->inicio()->viewData('primerosPasos')['pasos']['pago']['completo']);
    }

    public function test_copy_link_stays_visible_when_the_link_step_is_done_but_others_are_not(): void
    {
        $this->pedido();

        $respuesta = $this->inicio();

        $this->assertTrue($respuesta->viewData('primerosPasos')['pasos']['enlace']['completo']);
        $respuesta->assertSee('1 de 4 completos')->assertSee('Copiar enlace');
    }

    public function test_first_steps_disappear_when_everything_is_complete(): void
    {
        $this->completarCarta();
        $this->completarHorario();
        $this->activarYape('987654321');
        $this->pedido();

        $respuesta = $this->inicio();

        $this->assertNull($respuesta->viewData('primerosPasos'));
        $respuesta->assertDontSee('Primeros pasos')->assertDontSee('Copiar enlace');
    }

    public function test_real_zeros_are_shown_once_the_restaurant_has_had_orders(): void
    {
        $this->pedido(['estado' => 'cancelado', 'cancelado_at' => now()->subDays(3), 'created_at' => now()->subDays(3)]);

        $this->inicio()
            ->assertSee('Aún sin entregas hoy')
            ->assertSee('Ninguno esperando')
            ->assertDontSee('Atender 0')
            ->assertDontSee('Aquí verás cuántos recibes al día')
            ->assertDontSee('Todavía no recibes pedidos');
    }

    public function test_the_sample_data_and_unbuilt_blocks_are_gone(): void
    {
        $this->inicio()
            ->assertDontSee('Lucía Torres')
            ->assertDontSee('Mesas en tiempo real')
            ->assertDontSee('Reservas próximas')
            ->assertDontSee('Promociones activas')
            ->assertDontSee('Actividad reciente')
            ->assertDontSee('Estados de componentes')
            ->assertDontSee('Nuevo pedido')
            ->assertDontSee('Imprimir tickets');
    }

    public function test_query_count_does_not_grow_with_orders_and_alerts(): void
    {
        $this->completarCarta();

        $contarConsultas = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->inicio();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $pedido = $this->pedido();
        $this->alerta(['referencia_tipo' => 'pedido', 'referencia_id' => $pedido->id]);

        $conUno = $contarConsultas();

        foreach (range(1, 30) as $numero) {
            $otro = $this->pedido(['estado' => $numero % 2 === 0 ? 'nuevo' : 'entregado', 'entregado_at' => now()]);
            $this->alerta(['referencia_tipo' => 'pedido', 'referencia_id' => $otro->id]);
        }

        $this->assertSame($conUno, $contarConsultas());
        $this->assertLessThanOrEqual(20, $conUno);
    }
}
