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
        Schema::create('actividad_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_act_rest')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: 'fk_act_user')->nullOnDelete();
            $table->enum('modulo', ['pedidos', 'reservas', 'mesas', 'promociones', 'menu', 'configuracion', 'usuarios']);
            $table->string('accion', 40);
            $table->string('descripcion')->nullable();
            $table->string('subject_type', 30)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('dispositivo', 20)->nullable();
            $table->string('navegador', 80)->nullable();
            $table->boolean('revertible')->default(false);
            $table->timestamp('revertida_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['restaurante_id', 'created_at'], 'idx_act_fecha');
            $table->index(['restaurante_id', 'user_id', 'created_at'], 'idx_act_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('actividad_log');
    }
};
