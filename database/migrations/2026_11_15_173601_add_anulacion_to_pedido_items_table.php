<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una línea ya enviada a cocina no se borra: se anula con una marca, quién y por qué.
     * El subtotal y el total del pedido se recalculan sin las líneas anuladas.
     */
    public function up(): void
    {
        Schema::table('pedido_items', function (Blueprint $table) {
            $table->timestamp('anulado_at')->nullable()->after('listo_at');
            $table->foreignId('anulado_por')->nullable()->after('anulado_at')->constrained('users', indexName: 'fk_item_anulo')->nullOnDelete();
            $table->string('motivo_anulacion', 255)->nullable()->after('anulado_por');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pedido_items', function (Blueprint $table) {
            $table->dropForeign('fk_item_anulo');
        });

        Schema::table('pedido_items', function (Blueprint $table) {
            $table->dropColumn(['anulado_at', 'anulado_por', 'motivo_anulacion']);
        });
    }
};
