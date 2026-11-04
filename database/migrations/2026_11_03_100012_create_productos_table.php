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
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_prod_rest')->cascadeOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias_producto', indexName: 'fk_prod_cat')->nullOnDelete();
            $table->string('nombre', 120);
            $table->text('descripcion')->nullable();
            $table->string('imagen')->nullable();
            $table->decimal('precio', 8, 2);
            $table->boolean('disponible')->default(true);
            $table->boolean('destacado')->default(false);
            $table->enum('estacion', ['cocina', 'barra'])->default('cocina');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurante_id', 'categoria_id', 'disponible'], 'idx_prod_rest');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
