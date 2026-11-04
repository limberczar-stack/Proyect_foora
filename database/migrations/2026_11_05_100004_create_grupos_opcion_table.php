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
        Schema::create('grupos_opcion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_gop_rest')->cascadeOnDelete();
            $table->string('nombre', 80);
            $table->enum('tipo', ['unica', 'multiple'])->default('unica');
            $table->boolean('obligatorio')->default(false);
            $table->unsignedTinyInteger('min_opciones')->default(0);
            $table->unsignedTinyInteger('max_opciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('restaurante_id', 'idx_gop_rest');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grupos_opcion');
    }
};
