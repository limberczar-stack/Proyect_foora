<?php

namespace Tests\Feature\Superadmin;

use App\Models\ActividadLog;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevisarSuscripcionesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function enLima(string $momento): void
    {
        Carbon::setTestNow(Carbon::parse($momento, 'America/Lima'));
    }

    private function suscripcion(string $vence, string $estado = 'activa', ?Restaurante $restaurante = null): Suscripcion
    {
        return Suscripcion::factory()->create([
            'restaurante_id' => $restaurante?->id ?? Restaurante::factory(),
            'estado' => $estado,
            'inicia_el' => '2026-01-01',
            'vence_el' => $vence,
        ]);
    }

    public function test_it_expires_only_what_already_passed(): void
    {
        $this->enLima('2026-11-20 00:10');

        $paso = $this->suscripcion('2026-11-19');
        $pruebaPaso = $this->suscripcion('2026-11-19', 'prueba');
        $venceHoy = $this->suscripcion('2026-11-20');
        $futura = $this->suscripcion('2026-12-20');
        $cancelada = $this->suscripcion('2026-11-01', 'cancelada');

        $this->artisan('foora:revisar-suscripciones')
            ->expectsOutputToContain('Suscripciones marcadas como vencidas: 2.')
            ->assertSuccessful();

        $this->assertSame('vencida', $paso->fresh()->estado);
        $this->assertSame('vencida', $pruebaPaso->fresh()->estado);
        $this->assertSame('activa', $venceHoy->fresh()->estado);
        $this->assertSame('activa', $futura->fresh()->estado);
        $this->assertSame('cancelada', $cancelada->fresh()->estado);
    }

    public function test_the_date_is_lima_not_the_server(): void
    {
        $suscripcion = $this->suscripcion('2026-11-20');

        // En UTC ya es el 21 (03:30), pero en Lima todavía es el 20: vence hoy, no venció.
        $this->enLima('2026-11-20 22:30');
        $this->assertSame('2026-11-21', now('UTC')->toDateString());
        $this->artisan('foora:revisar-suscripciones')->assertSuccessful();
        $this->assertSame('activa', $suscripcion->fresh()->estado);

        // Un minuto después de la medianoche de Lima sí venció.
        $this->enLima('2026-11-21 00:01');
        $this->artisan('foora:revisar-suscripciones')->assertSuccessful();
        $this->assertSame('vencida', $suscripcion->fresh()->estado);
    }

    public function test_grace_and_out_of_grace(): void
    {
        $restaurante = Restaurante::factory()->create();
        $suscripcion = $this->suscripcion('2026-11-10', restaurante: $restaurante);

        // Dentro de la gracia: vencida, sigue operando.
        $this->enLima('2026-11-12 09:00');
        $this->artisan('foora:revisar-suscripciones')->assertSuccessful();

        $this->assertSame('vencida', $suscripcion->fresh()->estado);
        $fresco = $restaurante->fresh();
        $this->assertTrue($fresco->operativo());
        $this->assertTrue($fresco->enGracia());
        $this->assertSame(3, $fresco->diasDeGracia());
        $this->assertSame(0, ActividadLog::where('accion', 'fuera_de_operacion')->count());

        // Último día de gracia, justo antes de la medianoche de Lima.
        $this->enLima('2026-11-15 23:59');
        $this->artisan('foora:revisar-suscripciones')->assertSuccessful();
        $this->assertTrue($restaurante->fresh()->operativo());
        $this->assertSame(0, $restaurante->fresh()->diasDeGracia());

        // Pasó la medianoche: fuera de operación, y queda constancia.
        $this->enLima('2026-11-16 00:01');
        $this->artisan('foora:revisar-suscripciones')
            ->expectsOutputToContain('Locales que salieron de operación: 1.')
            ->assertSuccessful();
        $this->assertFalse($restaurante->fresh()->operativo());
        $this->assertSame(1, ActividadLog::where('accion', 'fuera_de_operacion')->where('restaurante_id', $restaurante->id)->count());
    }

    public function test_running_it_again_repeats_nothing(): void
    {
        $this->enLima('2026-11-30 09:00');
        $this->suscripcion('2026-11-10');

        $this->artisan('foora:revisar-suscripciones')->assertSuccessful();
        $primera = ActividadLog::count();
        $this->assertSame(2, $primera);

        $this->artisan('foora:revisar-suscripciones')
            ->expectsOutputToContain('Suscripciones marcadas como vencidas: 0. Locales que salieron de operación: 0.')
            ->assertSuccessful();
        $this->assertSame($primera, ActividadLog::count());
    }

    public function test_a_renewed_one_that_expires_again_leaves_a_new_record(): void
    {
        $this->enLima('2026-11-30 09:00');
        $suscripcion = $this->suscripcion('2026-11-10');

        $this->artisan('foora:revisar-suscripciones')->assertSuccessful();

        $suscripcion->update(['estado' => 'activa', 'vence_el' => '2026-12-10']);
        $this->enLima('2026-12-30 09:00');
        $this->artisan('foora:revisar-suscripciones')->assertSuccessful();

        $this->assertSame(2, ActividadLog::where('accion', 'fuera_de_operacion')->count());
    }

    public function test_the_log_has_no_user_and_records_before_and_after(): void
    {
        $this->enLima('2026-11-12 09:00');
        $suscripcion = $this->suscripcion('2026-11-10');

        $this->artisan('foora:revisar-suscripciones')->assertSuccessful();

        $log = ActividadLog::where('accion', 'suscripcion_vencida')->sole();
        $this->assertSame($suscripcion->restaurante_id, $log->restaurante_id);
        $this->assertNull($log->user_id);
        $this->assertSame('Sistema', $log->dispositivo);
        $this->assertSame('suscripciones', $log->modulo);
        $this->assertSame(['estado' => 'activa'], $log->datos_antes);
        $this->assertSame(['estado' => 'vencida'], $log->datos_despues);
    }

    public function test_it_is_scheduled_daily(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($evento): bool => str_contains((string) $evento->command, 'foora:revisar-suscripciones'));

        $this->assertNotNull($evento);
        $this->assertSame('10 0 * * *', $evento->expression);
        $this->assertSame('America/Lima', $evento->timezone);
    }
}
