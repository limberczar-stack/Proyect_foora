<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('alertas', function (Blueprint $table) {
            $table->enum('tipo', [
                'pago_pendiente', 'pedido_nuevo', 'pedido_sin_respuesta', 'reserva_por_vencer',
                'solicitud_aplazamiento', 'ocupacion_alta', 'menu_incompleto', 'otro',
            ])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('alertas')->where('tipo', 'pedido_nuevo')->delete();

        Schema::table('alertas', function (Blueprint $table) {
            $table->enum('tipo', [
                'pago_pendiente', 'pedido_sin_respuesta', 'reserva_por_vencer',
                'solicitud_aplazamiento', 'ocupacion_alta', 'menu_incompleto', 'otro',
            ])->change();
        });
    }
};
