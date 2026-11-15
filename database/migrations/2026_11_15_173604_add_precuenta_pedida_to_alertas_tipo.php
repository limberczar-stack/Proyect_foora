<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aviso a caja de que una mesa pidió la precuenta.
     */
    public function up(): void
    {
        Schema::table('alertas', function (Blueprint $table) {
            $table->enum('tipo', [
                'pago_pendiente', 'pedido_nuevo', 'pedido_sin_respuesta', 'reserva_por_vencer',
                'solicitud_aplazamiento', 'ocupacion_alta', 'menu_incompleto', 'precuenta_pedida', 'otro',
            ])->change();
        });
    }

    /**
     * Las alertas de precuenta no se borran: pasan a "otro" y conservan su título.
     */
    public function down(): void
    {
        DB::table('alertas')->where('tipo', 'precuenta_pedida')->update(['tipo' => 'otro']);

        Schema::table('alertas', function (Blueprint $table) {
            $table->enum('tipo', [
                'pago_pendiente', 'pedido_nuevo', 'pedido_sin_respuesta', 'reserva_por_vencer',
                'solicitud_aplazamiento', 'ocupacion_alta', 'menu_incompleto', 'otro',
            ])->change();
        });
    }
};
