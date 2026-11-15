<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulos que el equipo de FOORA encendió para el local (lista de claves, p. ej. ["mesas"]).
     * Nulo: ninguno. Ver Restaurante::tieneModulo() y App\Support\Modulos.
     */
    public function up(): void
    {
        Schema::table('restaurantes', function (Blueprint $table) {
            $table->json('modulos')->nullable()->after('config_notificaciones');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurantes', function (Blueprint $table) {
            $table->dropColumn('modulos');
        });
    }
};
