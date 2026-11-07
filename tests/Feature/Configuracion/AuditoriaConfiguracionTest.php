<?php

namespace Tests\Feature\Configuracion;

use App\Models\ActividadLog;
use App\Models\Restaurante;
use App\Models\User;
use App\Services\RegistrarActividad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditoriaConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    private const AGENTE_CELULAR = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

    public function test_a_change_is_logged_with_before_after_user_ip_and_device(): void
    {
        $restaurante = Restaurante::factory()->create(['costo_delivery' => 3]);
        $admin = User::factory()->admin($restaurante)->create();

        $this->actingAs($admin)
            ->withHeader('User-Agent', self::AGENTE_CELULAR)
            ->withServerVariables(['REMOTE_ADDR' => '181.65.10.20'])
            ->putJson(route('panel.configuracion.pedidos.update'), [
                'acepta_delivery' => '1', 'acepta_recojo' => '1', 'costo_delivery' => '6', 'pedido_minimo' => '0',
                'acepta_pedidos_auto' => '0', 'sonido_nuevos_pedidos' => '1', 'visible_en_app' => '1',
            ])
            ->assertOk();

        $log = ActividadLog::sole();
        $this->assertSame($restaurante->id, $log->restaurante_id);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('configuracion', $log->modulo);
        $this->assertSame('actualizo_pedidos', $log->accion);
        $this->assertSame('181.65.10.20', $log->ip);
        $this->assertSame('Móvil', $log->dispositivo);
        $this->assertSame('Chrome', $log->navegador);
        $this->assertSame('3.00', $log->datos_antes['costo_delivery']);
        $this->assertSame('6.00', $log->datos_despues['costo_delivery']);
        $this->assertArrayNotHasKey('pedido_minimo', $log->datos_despues, 'Solo guarda lo que cambió.');
    }

    public function test_saving_without_changes_leaves_no_entry(): void
    {
        $restaurante = Restaurante::factory()->create();
        $admin = User::factory()->admin($restaurante)->create();

        $this->actingAs($admin)
            ->putJson(route('panel.configuracion.notificaciones.update'), ['nuevos_pedidos' => '1', 'reservas' => '1', 'alertas' => '1'])
            ->assertOk();

        $this->assertSame(0, ActividadLog::count());
    }

    public function test_user_management_and_password_changes_never_store_secrets(): void
    {
        $restaurante = Restaurante::factory()->create();
        $admin = User::factory()->admin($restaurante)->create();

        $this->actingAs($admin);
        $temporal = $this->postJson(route('panel.configuracion.usuarios.store'), ['name' => 'Ana', 'email' => 'ana@local.pe', 'rol' => 'cocina'])->json('password_temporal');
        $ana = User::where('email', 'ana@local.pe')->sole();
        $otraTemporal = $this->postJson(route('panel.configuracion.usuarios.password', $ana->id))->json('password_temporal');
        $this->patchJson(route('panel.configuracion.usuarios.estado', $ana->id), ['activo' => '0'])->assertOk();
        $this->putJson(route('panel.mi-cuenta.password'), ['password_actual' => 'password', 'password' => 'clave-secreta-nueva', 'password_confirmation' => 'clave-secreta-nueva'])->assertOk();

        $logs = ActividadLog::orderBy('id')->get();
        $this->assertSame(['creo_usuario', 'restablecio_password', 'desactivo_usuario', 'cambio_password'], $logs->pluck('accion')->all());
        $this->assertTrue($logs->every(fn (ActividadLog $log): bool => $log->modulo === 'usuarios'));

        $todo = $logs->toJson().json_encode($logs->map->getAttributes());
        foreach ([$temporal, $otraTemporal, 'clave-secreta-nueva', $ana->fresh()->password, $admin->fresh()->password, '"password":', '"remember_token":', '"password_actual":'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $todo);
        }

        $this->assertSame(['name' => 'Ana', 'email' => 'ana@local.pe', 'telefono' => null, 'rol' => 'cocina', 'activo' => true], $logs[0]->datos_despues);
    }

    public function test_the_filter_removes_sensitive_keys_at_any_depth(): void
    {
        $limpio = RegistrarActividad::limpiar([
            'name' => 'Ana',
            'password' => 'x',
            'Remember_Token' => 'y',
            'anidado' => ['token' => 'z', 'password_confirmation' => 'w', 'rol' => 'cajero'],
        ]);

        $this->assertSame(['name' => 'Ana', 'anidado' => ['rol' => 'cajero']], $limpio);
    }
}
