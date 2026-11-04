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
        Schema::create('pedido_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos', indexName: 'fk_ph_pedido')->cascadeOnDelete();
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20);
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: 'fk_ph_user')->nullOnDelete();
            $table->string('nota')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['pedido_id', 'created_at'], 'idx_ph_pedido');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedido_historial');
    }
};
