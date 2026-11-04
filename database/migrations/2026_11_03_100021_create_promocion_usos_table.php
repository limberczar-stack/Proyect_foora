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
        Schema::create('promocion_usos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promocion_id')->constrained('promociones', indexName: 'fk_pu_promo')->cascadeOnDelete();
            $table->foreignId('pedido_id')->constrained('pedidos', indexName: 'fk_pu_pedido')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: 'fk_pu_user')->nullOnDelete();
            $table->decimal('descuento_aplicado', 10, 2)->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['promocion_id', 'pedido_id'], 'uq_promo_pedido');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promocion_usos');
    }
};
