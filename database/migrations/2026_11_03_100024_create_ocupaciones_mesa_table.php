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
        Schema::create('ocupaciones_mesa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_ocu_rest')->cascadeOnDelete();
            $table->foreignId('mesa_id')->constrained('mesas', indexName: 'fk_ocu_mesa')->cascadeOnDelete();
            $table->foreignId('reserva_id')->nullable()->constrained('reservas', indexName: 'fk_ocu_res')->nullOnDelete();
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos', indexName: 'fk_ocu_ped')->nullOnDelete();
            $table->foreignId('mesero_id')->nullable()->constrained('users', indexName: 'fk_ocu_mesero')->nullOnDelete();
            $table->unsignedTinyInteger('personas')->default(1);
            $table->timestamp('ingreso_at');
            $table->timestamp('salida_at')->nullable();
            $table->decimal('consumo_total', 10, 2)->default(0);
            $table->timestamps();

            $table->index(['mesa_id', 'salida_at'], 'idx_ocu_mesa');
            $table->index(['restaurante_id', 'ingreso_at'], 'idx_ocu_rest');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ocupaciones_mesa');
    }
};
