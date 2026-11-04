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
        Schema::create('resenas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_resena_rest')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', indexName: 'fk_resena_user')->cascadeOnDelete();
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos', indexName: 'fk_resena_pedido')->nullOnDelete();
            $table->unsignedTinyInteger('puntuacion');
            $table->string('comentario', 500)->nullable();
            $table->timestamps();

            $table->unique('pedido_id', 'uq_resena_pedido');
            $table->index(['restaurante_id', 'created_at'], 'idx_resena_rest');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resenas');
    }
};
