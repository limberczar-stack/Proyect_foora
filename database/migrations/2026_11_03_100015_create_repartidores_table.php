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
        Schema::create('repartidores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_rep_rest')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: 'fk_rep_user')->nullOnDelete();
            $table->string('nombre', 120);
            $table->string('telefono', 20)->nullable();
            $table->string('vehiculo', 30)->nullable();
            $table->string('placa', 15)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repartidores');
    }
};
