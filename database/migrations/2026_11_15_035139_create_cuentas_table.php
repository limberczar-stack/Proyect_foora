<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuentas de salón: una atención abierta en una o varias mesas (ver ocupaciones_mesa.cuenta_id).
     *
     * - numero: correlativo por local, sin huecos; lo asigna GestionarCuentas con el restaurante bloqueado.
     * - version: sube con cada operación; quien actúa envía la que veía y, si cambió, recibe 409.
     * - Nada se borra: una cuenta se cierra, se anula (con motivo) o queda fusionada en otra.
     * - Los estados precuenta y el cierre por venta llegan en fases siguientes.
     */
    public function up(): void
    {
        Schema::create('cuentas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_cta_rest')->cascadeOnDelete();
            $table->unsignedInteger('numero');
            $table->enum('estado', ['abierta', 'precuenta', 'cerrada', 'anulada', 'fusionada'])->default('abierta');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('mesero_id')->nullable()->constrained('users', indexName: 'fk_cta_mesero')->nullOnDelete();
            $table->unsignedTinyInteger('comensales');
            $table->timestamp('abierta_at');
            $table->timestamp('cerrada_at')->nullable();
            $table->foreignId('cerrada_por')->nullable()->constrained('users', indexName: 'fk_cta_cerro')->nullOnDelete();
            $table->enum('cierre_tipo', ['externo', 'venta'])->nullable();
            $table->foreignId('fusionada_en_id')->nullable()->constrained('cuentas', indexName: 'fk_cta_fusion')->nullOnDelete();
            $table->string('nota', 255)->nullable();
            $table->timestamp('anulada_at')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('users', indexName: 'fk_cta_anulo')->nullOnDelete();
            $table->string('motivo_anulacion', 255)->nullable();
            $table->timestamps();

            $table->unique(['restaurante_id', 'numero'], 'uq_cuenta_num');
            $table->index(['restaurante_id', 'estado'], 'idx_cuenta_estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cuentas');
    }
};
