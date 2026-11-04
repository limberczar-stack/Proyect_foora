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
        Schema::create('horarios_atencion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_hor_rest')->cascadeOnDelete();
            $table->unsignedTinyInteger('dia_semana');
            $table->time('abre')->nullable();
            $table->time('cierra')->nullable();
            $table->boolean('cerrado')->default(false);
            $table->timestamps();

            $table->unique(['restaurante_id', 'dia_semana'], 'uq_horario');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('horarios_atencion');
    }
};
