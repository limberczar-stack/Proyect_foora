<?php

namespace App\Console\Commands;

use App\Models\ActividadLog;
use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Services\RegistrarActividad;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('foora:revisar-suscripciones')]
#[Description('Marca como vencidas las suscripciones cuyo vence_el ya pasó y deja constancia de los locales que salen de operación')]
class RevisarSuscripciones extends Command
{
    /**
     * Las fechas se comparan en la zona de cada local. Se puede correr varias veces: no repite nada.
     */
    public function handle(RegistrarActividad $actividad): int
    {
        $vencidas = 0;
        $fueraDeOperacion = 0;

        Restaurante::query()
            ->whereHas('suscripcionActual', fn ($query) => $query->where('estado', '!=', 'cancelada'))
            ->with('suscripcionActual')
            ->chunkById(100, function (Collection $restaurantes) use ($actividad, &$vencidas, &$fueraDeOperacion): void {
                foreach ($restaurantes as $restaurante) {
                    /** @var Restaurante $restaurante */
                    if ($this->marcarVencida($restaurante, $actividad)) {
                        $vencidas++;
                    }

                    if ($this->registrarFueraDeOperacion($restaurante, $actividad)) {
                        $fueraDeOperacion++;
                    }
                }
            });

        $this->info("Suscripciones marcadas como vencidas: {$vencidas}. Locales que salieron de operación: {$fueraDeOperacion}.");

        return self::SUCCESS;
    }

    private function marcarVencida(Restaurante $restaurante, RegistrarActividad $actividad): bool
    {
        $suscripcion = $restaurante->suscripcionActual;
        $anterior = $suscripcion->estado;

        if ($anterior === 'vencida' || $suscripcion->estadoEl($restaurante->fechaHoy()) !== 'vencida') {
            return false;
        }

        $suscripcion->update(['estado' => 'vencida']);

        $actividad->registrarDelSistema($restaurante, 'suscripciones', 'suscripcion_vencida',
            "La suscripción venció el {$suscripcion->vence_el->format('d/m/Y')}.", $suscripcion,
            ['estado' => $anterior], ['estado' => 'vencida']);

        return true;
    }

    /**
     * Pasada la gracia queda constancia una sola vez por cada vencimiento.
     */
    private function registrarFueraDeOperacion(Restaurante $restaurante, RegistrarActividad $actividad): bool
    {
        $suscripcion = $restaurante->suscripcionActual;

        if ($restaurante->estaSuspendido() || $restaurante->operativo()) {
            return false;
        }

        $yaConsta = ActividadLog::withoutGlobalScope(ActividadLog::RESTAURANTE_SCOPE)
            ->where('restaurante_id', $restaurante->id)
            ->where('accion', 'fuera_de_operacion')
            ->where('subject_id', $suscripcion->id)
            ->where('datos_despues->vence_el', $suscripcion->vence_el->toDateString())
            ->exists();

        if ($yaConsta) {
            return false;
        }

        $actividad->registrarDelSistema($restaurante, 'suscripciones', 'fuera_de_operacion',
            'El local salió de operación: pasó el periodo de gracia de '.Suscripcion::DIAS_GRACIA.' días.', $suscripcion,
            null, ['vence_el' => $suscripcion->vence_el->toDateString()]);

        return true;
    }
}
