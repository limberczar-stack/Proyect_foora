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
        Schema::create('promociones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_promo_rest')->cascadeOnDelete();
            $table->string('nombre', 120);
            $table->string('descripcion')->nullable();
            $table->string('imagen')->nullable();
            $table->enum('tipo', ['2x1', 'porcentaje', 'monto_fijo', 'delivery_gratis', 'combo']);
            $table->decimal('valor', 8, 2)->nullable();
            $table->decimal('monto_minimo', 8, 2)->nullable();
            $table->boolean('aplica_delivery')->default(true);
            $table->boolean('aplica_recojo')->default(true);
            $table->boolean('aplica_salon')->default(true);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedInteger('limite_usos')->nullable();
            $table->unsignedInteger('usos')->default(0);
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurante_id', 'activa', 'fecha_inicio', 'fecha_fin'], 'idx_promo_vig');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promociones');
    }
};
