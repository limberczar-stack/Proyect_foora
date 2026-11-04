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
        Schema::create('suscripciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_susc_rest')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('planes', indexName: 'fk_susc_plan');
            $table->enum('estado', ['prueba', 'activa', 'vencida', 'cancelada'])->default('prueba');
            $table->date('inicia_el');
            $table->date('vence_el');
            $table->decimal('precio_pactado', 8, 2)->default(0);
            $table->timestamps();

            $table->index(['restaurante_id', 'estado', 'vence_el'], 'idx_susc_rest');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suscripciones');
    }
};
