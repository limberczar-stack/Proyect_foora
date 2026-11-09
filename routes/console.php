<?php

use App\Models\Restaurante;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Después de la medianoche de Perú: las fechas de vencimiento son las del local.
Schedule::command('foora:revisar-suscripciones')
    ->dailyAt('00:10')
    ->timezone(Restaurante::ZONA_POR_DEFECTO)
    ->withoutOverlapping();
