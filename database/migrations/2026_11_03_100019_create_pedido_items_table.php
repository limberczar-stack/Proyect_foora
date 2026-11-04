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
        Schema::create('pedido_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos', indexName: 'fk_item_pedido')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos', indexName: 'fk_item_prod')->nullOnDelete();
            $table->string('nombre', 120);
            $table->unsignedSmallInteger('cantidad')->default(1);
            $table->decimal('precio_unitario', 8, 2);
            $table->decimal('subtotal', 10, 2);
            $table->enum('estado', ['pendiente', 'en_preparacion', 'listo'])->default('pendiente');
            $table->string('observacion')->nullable();
            $table->unsignedSmallInteger('tiempo_estimado_min')->nullable();
            $table->timestamp('listo_at')->nullable();
            $table->timestamps();

            $table->index('pedido_id', 'idx_item_pedido');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedido_items');
    }
};
