<?php

namespace Tests\Feature\Vitrina;

use App\Models\Restaurante;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HorarioRestauranteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Momento en hora de Lima. El 2 de noviembre de 2026 es lunes.
     */
    private function enLima(string $momento): Carbon
    {
        return Carbon::parse($momento, 'America/Lima');
    }

    /**
     * @param  array<int, array{0: ?string, 1: ?string, 2?: bool}>  $dias
     */
    private function restaurante(array $dias, string $estado = 'abierto'): Restaurante
    {
        $restaurante = Restaurante::factory()->create(['estado_local' => $estado]);

        foreach ($dias as $dia => $horario) {
            $restaurante->horariosAtencion()->create([
                'dia_semana' => $dia,
                'abre' => $horario[0],
                'cierra' => $horario[1],
                'cerrado' => $horario[2] ?? false,
            ]);
        }

        return $restaurante->refresh();
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function horarioNormal(): array
    {
        return [
            'antes de abrir' => ['2026-11-02 11:59', false],
            'al abrir' => ['2026-11-02 12:00', true],
            'a media tarde' => ['2026-11-02 18:00', true],
            'a la hora de cerrar' => ['2026-11-02 22:00', false],
        ];
    }

    #[DataProvider('horarioNormal')]
    public function test_regular_schedule(string $momento, bool $abierto): void
    {
        $restaurante = $this->restaurante([1 => ['12:00:00', '22:00:00']]);

        $this->assertSame($abierto, $restaurante->abiertoAhora($this->enLima($momento)));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function horarioQueCruzaMedianoche(): array
    {
        return [
            'lunes antes de abrir' => ['2026-11-02 17:00', false],
            'lunes de noche' => ['2026-11-02 23:30', true],
            'martes de madrugada' => ['2026-11-03 00:59', true],
            'martes a la hora de cerrar' => ['2026-11-03 01:00', false],
            'martes de día, sin horario' => ['2026-11-03 12:00', false],
        ];
    }

    #[DataProvider('horarioQueCruzaMedianoche')]
    public function test_schedule_that_crosses_midnight(string $momento, bool $abierto): void
    {
        $restaurante = $this->restaurante([1 => ['18:00:00', '01:00:00']]);

        $this->assertSame($abierto, $restaurante->abiertoAhora($this->enLima($momento)));
    }

    public function test_sunday_night_schedule_reaches_monday_morning(): void
    {
        $restaurante = $this->restaurante([7 => ['18:00:00', '01:00:00']]);

        $this->assertTrue($restaurante->abiertoAhora($this->enLima('2026-11-02 00:30')));
    }

    public function test_closed_day_and_missing_day_are_closed(): void
    {
        $restaurante = $this->restaurante([1 => [null, null, true], 3 => ['12:00:00', '22:00:00']]);

        $this->assertFalse($restaurante->abiertoAhora($this->enLima('2026-11-02 15:00')));
        $this->assertFalse($restaurante->abiertoAhora($this->enLima('2026-11-03 15:00')));
        $this->assertSame('Hoy no atiende', $restaurante->horarioDeHoyTexto($this->enLima('2026-11-02 15:00')));
    }

    public function test_closing_the_local_by_hand_wins_over_the_schedule(): void
    {
        $restaurante = $this->restaurante([1 => ['12:00:00', '22:00:00']], 'cerrado');

        $this->assertFalse($restaurante->abiertoAhora($this->enLima('2026-11-02 15:00')));
    }

    public function test_without_schedules_only_the_local_state_counts(): void
    {
        $abierto = Restaurante::factory()->create();
        $cerrado = Restaurante::factory()->cerrado()->create();

        $this->assertTrue($abierto->abiertoAhora());
        $this->assertFalse($cerrado->abiertoAhora());
        $this->assertNull($abierto->horarioDeHoyTexto());
    }

    public function test_time_is_evaluated_in_the_restaurant_time_zone(): void
    {
        $restaurante = $this->restaurante([1 => ['12:00:00', '22:00:00']]);

        // 03:00 UTC del martes son las 22:00 del lunes en Lima: ya cerró.
        $this->assertFalse($restaurante->abiertoAhora(Carbon::parse('2026-11-03 03:00', 'UTC')));
        // 02:59 UTC del martes son las 21:59 del lunes en Lima: sigue abierto.
        $this->assertTrue($restaurante->abiertoAhora(Carbon::parse('2026-11-03 02:59', 'UTC')));
    }
}
