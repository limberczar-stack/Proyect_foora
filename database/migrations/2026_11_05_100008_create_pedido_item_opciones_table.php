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
        Schema::create('pedido_item_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_item_id')->constrained('pedido_items', indexName: 'fk_pio_item')->cascadeOnDelete();
            $table->foreignId('opcion_id')->nullable()->constrained('opciones', indexName: 'fk_pio_opcion')->nullOnDelete();
            $table->string('grupo_nombre', 80);
            $table->string('nombre', 80);
            $table->decimal('precio_extra', 8, 2)->default(0);

            $table->index('pedido_item_id', 'idx_pio_item');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedido_item_opciones');
    }
};
