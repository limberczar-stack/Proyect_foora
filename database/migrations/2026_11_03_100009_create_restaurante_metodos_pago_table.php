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
        Schema::create('restaurante_metodos_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_rmp_rest')->cascadeOnDelete();
            $table->foreignId('metodo_pago_id')->constrained('metodos_pago', indexName: 'fk_rmp_metodo');
            $table->boolean('activo')->default(true);
            $table->string('numero_cuenta', 30)->nullable();
            $table->string('titular', 120)->nullable();
            $table->timestamps();

            $table->unique(['restaurante_id', 'metodo_pago_id'], 'uq_rest_metodo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurante_metodos_pago');
    }
};
