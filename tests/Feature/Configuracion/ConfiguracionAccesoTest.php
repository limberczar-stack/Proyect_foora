<?php

namespace Tests\Feature\Configuracion;

use App\Models\Restaurante;
use App\Models\User;
use Database\Seeders\MetodoPagoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConfiguracionAccesoTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const SECCIONES = ['general', 'horario', 'pago', 'pedidos', 'notificaciones', 'usuarios'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MetodoPagoSeeder::class);
    }

    public function test_admin_opens_every_section(): void
    {
        $restaurante = Restaurante::factory()->create(['nombre' => 'Pollería El Buen Sabor']);
        $admin = User::factory()->admin($restaurante)->create();

        foreach (self::SECCIONES as $seccion) {
            $this->actingAs($admin)
                ->get(route('panel.configuracion.'.$seccion))
                ->assertOk()
                ->assertSee('Configuración')
                ->assertSee('Pollería El Buen Sabor');
        }

        $this->get(route('panel.configuracion.index'))->assertRedirect('/panel/configuracion/general');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesSinAcceso(): array
    {
        return [
            'cajero' => ['cajero'],
            'cocina' => ['cocina'],
            'mesero' => ['mesero'],
            'repartidor' => ['repartidor'],
        ];
    }

    #[DataProvider('rolesSinAcceso')]
    public function test_other_roles_get_forbidden_on_every_section_and_save(string $rol): void
    {
        $restaurante = Restaurante::factory()->create(['nombre' => 'Original']);
        $usuario = User::factory()->personal($rol, $restaurante)->create();

        foreach (self::SECCIONES as $seccion) {
            $this->actingAs($usuario)->get(route('panel.configuracion.'.$seccion))->assertForbidden();
        }

        $this->putJson(route('panel.configuracion.general.update'), ['nombre' => 'Hackeado', 'ciudad' => 'Lima'])->assertForbidden();
        $this->putJson(route('panel.configuracion.pedidos.update'), ['acepta_delivery' => 1, 'costo_delivery' => 0, 'pedido_minimo' => 0])->assertForbidden();
        $this->postJson(route('panel.configuracion.usuarios.store'), ['name' => 'X', 'email' => 'x@x.pe', 'rol' => 'admin'])->assertForbidden();

        $this->assertSame('Original', $restaurante->fresh()->nombre);
        $this->assertDatabaseMissing('users', ['email' => 'x@x.pe']);
    }

    public function test_only_the_admin_sees_the_configuration_link(): void
    {
        $restaurante = Restaurante::factory()->create();

        $this->actingAs(User::factory()->admin($restaurante)->create())
            ->get(route('panel.inicio'))
            ->assertSee(route('panel.configuracion.general'));

        $this->actingAs(User::factory()->cajero($restaurante)->create())
            ->get(route('panel.inicio'))
            ->assertDontSee(route('panel.configuracion.general'));
    }

    public function test_superadmin_is_asked_to_pick_a_restaurant(): void
    {
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)
            ->get(route('panel.configuracion.general'))
            ->assertForbidden()
            ->assertSee('Selecciona un restaurante.');

        $this->actingAs($superadmin)
            ->get(route('panel.configuracion.usuarios'))
            ->assertForbidden()
            ->assertSee('Selecciona un restaurante.');

        $this->putJson(route('panel.configuracion.general.update'), ['nombre' => 'X', 'ciudad' => 'Lima'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Selecciona un restaurante.');
    }

    public function test_admin_only_changes_and_sees_their_own_restaurant(): void
    {
        $propio = Restaurante::factory()->create(['nombre' => 'Mi local', 'ciudad' => 'Huánuco']);
        $ajeno = Restaurante::factory()->create(['nombre' => 'Local ajeno', 'ciudad' => 'Tingo María', 'costo_delivery' => 7]);
        $admin = User::factory()->admin($propio)->create();
        User::factory()->admin($ajeno)->create(['name' => 'Persona del otro local', 'email' => 'otro@ajeno.pe']);

        // Aunque se envíe el id de otro restaurante, se guarda en el propio.
        $this->actingAs($admin)
            ->putJson(route('panel.configuracion.general.update'), [
                'nombre' => 'Mi local renovado',
                'ciudad' => 'Huánuco',
                'restaurante_id' => $ajeno->id,
                'id' => $ajeno->id,
            ])
            ->assertOk();

        $this->assertSame('Mi local renovado', $propio->fresh()->nombre);
        $this->assertSame('Local ajeno', $ajeno->fresh()->nombre);

        $this->get(route('panel.configuracion.usuarios'))
            ->assertOk()
            ->assertDontSee('Persona del otro local')
            ->assertDontSee('otro@ajeno.pe');

        $this->get(route('panel.configuracion.general'))->assertDontSee('Local ajeno');
    }
}
