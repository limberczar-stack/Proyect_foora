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
        Schema::create('mesas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_mesa_rest')->cascadeOnDelete();
            $table->foreignId('area_id')->constrained('areas', indexName: 'fk_mesa_area')->cascadeOnDelete();
            $table->string('codigo', 10);
            $table->unsignedTinyInteger('capacidad');
            $table->enum('estado', ['disponible', 'ocupada', 'reservada', 'fuera_servicio'])->default('disponible');
            $table->smallInteger('pos_x')->nullable();
            $table->smallInteger('pos_y')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['restaurante_id', 'codigo'], 'uq_mesa');
            $table->index(['restaurante_id', 'estado'], 'idx_mesa_estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mesas');
    }
};
