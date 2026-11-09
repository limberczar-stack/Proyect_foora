<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alta, plan, suspensión y pagos que hace el superadmin quedan en su propio módulo.
     */
    public function up(): void
    {
        Schema::table('actividad_log', function (Blueprint $table) {
            $table->enum('modulo', ['pedidos', 'reservas', 'mesas', 'promociones', 'menu', 'configuracion', 'usuarios', 'suscripciones'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('actividad_log')->where('modulo', 'suscripciones')->delete();

        Schema::table('actividad_log', function (Blueprint $table) {
            $table->enum('modulo', ['pedidos', 'reservas', 'mesas', 'promociones', 'menu', 'configuracion', 'usuarios'])->change();
        });
    }
};
