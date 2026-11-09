<?php

namespace Tests\Feature\Superadmin;

use App\Models\ActividadLog;
use App\Models\Area;
use App\Models\HorarioAtencion;
use App\Models\MetodoPago;
use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\RestauranteMetodoPago;
use App\Models\Suscripcion;
use App\Models\User;
use App\Services\CrearRestaurante;
use Carbon\Carbon;
use Database\Seeders\MetodoPagoSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AltaRestauranteTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PlanSeeder::class, MetodoPagoSeeder::class]);
        $this->superadmin = User::factory()->superadmin()->create();
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        return [
            'nombre' => 'Chifa Nuevo',
            'slug' => 'chifa-nuevo',
            'ciudad' => 'Huánuco',
            'telefono' => '+51 987 654 321',
            'direccion' => 'Jr. Abtao 120',
            'plan_id' => Plan::where('nombre', 'Pro')->value('id'),
            'admin_nombre' => 'Lina Paz',
            'admin_email' => 'Lina@Chifa.pe',
            ...$cambios,
        ];
    }

    public function test_full_signup_creates_the_local_with_all_its_initial_values(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 23:30', 'America/Lima'));

        $respuesta = $this->actingAs($this->superadmin)
            ->postJson(route('panel.superadmin.restaurantes.store'), $this->datos())
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('admin.email', 'lina@chifa.pe');

        $restaurante = Restaurante::where('slug', 'chifa-nuevo')->sole();
        $this->assertSame('987654321', $restaurante->telefono);
        $this->assertSame('cerrado', $restaurante->fresh()->estado_local);
        $this->assertTrue($restaurante->operativo());

        $admin = User::where('email', 'lina@chifa.pe')->sole();
        $this->assertSame($restaurante->id, $admin->restaurante_id);
        $this->assertSame('admin', $admin->rol);
        $this->assertTrue($admin->debe_cambiar_password);
        $this->assertTrue(Hash::check($respuesta->json('password_temporal'), $admin->password));

        $this->assertSame(7, HorarioAtencion::where('restaurante_id', $restaurante->id)->where('cerrado', false)->count());

        $metodos = RestauranteMetodoPago::where('restaurante_id', $restaurante->id)->get();
        $this->assertCount(1, $metodos);
        $this->assertSame(MetodoPago::where('codigo', 'efectivo')->value('id'), $metodos[0]->metodo_pago_id);
        $this->assertTrue($metodos[0]->activo);

        $this->assertSame(['Salón principal'], Area::where('restaurante_id', $restaurante->id)->pluck('nombre')->all());

        // Las fechas son las de Lima: a las 23:30 del 10 todavía es el 10, aunque en UTC ya sea el 11.
        $suscripcion = Suscripcion::where('restaurante_id', $restaurante->id)->sole();
        $this->assertSame('prueba', $suscripcion->estado);
        $this->assertSame('0.00', $suscripcion->precio_pactado);
        $this->assertSame('2026-11-10', $suscripcion->inicia_el->toDateString());
        $this->assertSame('2026-12-10', $suscripcion->vence_el->toDateString());
        $this->assertSame(Suscripcion::DIAS_PRUEBA, 30);
        $this->assertSame($restaurante->plan_id, $suscripcion->plan_id);

        Carbon::setTestNow();
    }

    public function test_the_temporary_password_is_only_in_the_response(): void
    {
        $temporal = $this->actingAs($this->superadmin)
            ->postJson(route('panel.superadmin.restaurantes.store'), $this->datos())
            ->json('password_temporal');

        $this->assertSame(12, strlen($temporal));
        $this->assertStringNotContainsString($temporal, json_encode(session()->all()));
        $this->assertStringNotContainsString($temporal, ActividadLog::all()->toJson());

        $restaurante = Restaurante::where('slug', 'chifa-nuevo')->sole();
        $this->get(route('panel.superadmin.restaurantes.show', $restaurante))->assertOk()->assertDontSee($temporal);
    }

    public function test_a_new_admin_must_change_the_temporary_password_and_their_panel_works(): void
    {
        $this->actingAs($this->superadmin)->postJson(route('panel.superadmin.restaurantes.store'), $this->datos())->assertCreated();

        $admin = User::where('email', 'lina@chifa.pe')->sole();
        $this->actingAs($admin)->get(route('panel.inicio'))->assertRedirect(route('panel.mi-cuenta'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function slugsInvalidos(): array
    {
        return [
            'reservado' => ['panel'],
            'otro reservado' => ['login'],
            'con mayúsculas' => ['Chifa-Nuevo'],
            'con espacios' => ['chifa nuevo'],
            'con guion al final' => ['chifa-'],
            'con tilde' => ['pollería'],
        ];
    }

    #[DataProvider('slugsInvalidos')]
    public function test_reserved_or_malformed_slugs_are_rejected(string $slug): void
    {
        $this->actingAs($this->superadmin)
            ->postJson(route('panel.superadmin.restaurantes.store'), $this->datos(['slug' => $slug]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');

        $this->assertSame(0, Restaurante::count());
    }

    public function test_a_repeated_slug_is_rejected_even_if_the_other_local_was_deleted(): void
    {
        Restaurante::factory()->create(['slug' => 'chifa-nuevo']);
        $eliminado = Restaurante::factory()->create(['slug' => 'chifa-viejo']);
        $eliminado->delete();

        $this->actingAs($this->superadmin);

        $this->postJson(route('panel.superadmin.restaurantes.store'), $this->datos())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug' => 'Otro restaurante ya usa esta dirección.']);

        $this->postJson(route('panel.superadmin.restaurantes.store'), $this->datos(['slug' => 'chifa-viejo']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    public function test_required_fields_and_unique_email_come_back_per_field(): void
    {
        User::factory()->create(['email' => 'lina@chifa.pe']);

        $this->actingAs($this->superadmin)
            ->postJson(route('panel.superadmin.restaurantes.store'), $this->datos(['nombre' => '', 'ciudad' => '', 'plan_id' => 999, 'admin_nombre' => '', 'telefono' => '123']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'ciudad', 'plan_id', 'admin_nombre', 'telefono', 'admin_email' => 'Ya existe una cuenta con ese correo. Usa otro.']);

        $this->assertSame(0, Restaurante::count());
    }

    public function test_signup_is_atomic_if_something_fails_nothing_remains(): void
    {
        // Sin Efectivo en el catálogo, el alta falla después de crear el local, su admin y el horario.
        MetodoPago::where('codigo', 'efectivo')->delete();
        $usuarios = User::count();
        $datos = [
            'nombre' => 'A medias', 'slug' => 'a-medias', 'ciudad' => 'Huánuco', 'plan_id' => Plan::value('id'),
            'admin_nombre' => 'Lina', 'admin_email' => 'lina@medias.pe',
        ];

        try {
            app(CrearRestaurante::class)->crear($this->superadmin, $datos);
            $this->fail('Debió fallar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Efectivo', $e->getMessage());
        }

        $this->assertSame(0, Restaurante::withTrashed()->count());
        $this->assertSame($usuarios, User::count());
        $this->assertSame(0, HorarioAtencion::count());
        $this->assertSame(0, Area::count());
        $this->assertSame(0, Suscripcion::count());
        $this->assertSame(0, ActividadLog::count());
    }

    public function test_signup_is_throttled(): void
    {
        $this->actingAs($this->superadmin);

        foreach (range(1, 10) as $intento) {
            $this->postJson(route('panel.superadmin.restaurantes.store'), ['nombre' => ''])->assertUnprocessable();
        }

        $this->postJson(route('panel.superadmin.restaurantes.store'), $this->datos())
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Hiciste muchas altas seguidas. Espera un minuto e inténtalo otra vez.');
    }

    public function test_the_signup_form_opens(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('panel.superadmin.restaurantes.create'))
            ->assertOk()
            ->assertSee('Nuevo restaurante')
            ->assertSee('Dar de alta');
    }
}
