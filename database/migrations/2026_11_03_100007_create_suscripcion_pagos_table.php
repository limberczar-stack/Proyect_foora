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
        Schema::create('suscripcion_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones', indexName: 'fk_spag_susc')->cascadeOnDelete();
            $table->decimal('monto', 8, 2);
            $table->string('metodo', 30)->nullable();
            $table->string('referencia', 80)->nullable();
            $table->date('periodo_desde');
            $table->date('periodo_hasta');
            $table->timestamp('pagado_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suscripcion_pagos');
    }
};
