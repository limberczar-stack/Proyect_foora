<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('alertas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_alerta_rest')->cascadeOnDelete();
            $table->enum('tipo', [
                'pago_pendiente', 'pedido_sin_respuesta', 'reserva_por_vencer',
                'solicitud_aplazamiento', 'ocupacion_alta', 'menu_incompleto', 'otro',
            ]);
            $table->enum('prioridad', ['urgente', 'media', 'informativa']);
            $table->string('titulo', 120);
            $table->string('mensaje')->nullable();
            $table->string('referencia_tipo', 30)->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->boolean('leida')->default(false);
            $table->timestamp('leida_at')->nullable();
            $table->timestamp('resuelta_at')->nullable();
            $table->timestamps();

            $table->index(['restaurante_id', 'resuelta_at', 'prioridad', 'created_at'], 'idx_alerta_pend');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alertas');
    }
};
