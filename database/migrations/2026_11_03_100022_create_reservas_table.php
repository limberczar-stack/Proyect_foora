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
        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 25)->unique();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_res_rest')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: 'fk_res_user')->nullOnDelete();
            $table->string('nombre_cliente', 120);
            $table->string('telefono_cliente', 20);
            $table->foreignId('mesa_id')->nullable()->constrained('mesas', indexName: 'fk_res_mesa')->nullOnDelete();
            $table->date('fecha');
            $table->time('hora');
            $table->unsignedTinyInteger('personas');
            $table->string('observaciones')->nullable();
            $table->enum('estado', ['pendiente', 'confirmada', 'cancelada', 'no_asistio', 'completada'])->default('pendiente');
            $table->enum('origen', ['app', 'local', 'telefono'])->default('app');
            $table->timestamp('confirmada_at')->nullable();
            $table->string('confirmada_por', 30)->nullable();
            $table->timestamp('cancelada_at')->nullable();
            $table->string('cancelacion_motivo')->nullable();
            $table->timestamp('recordatorio_enviado_at')->nullable();
            $table->timestamps();

            $table->index(['restaurante_id', 'fecha', 'hora'], 'idx_res_dia');
            $table->index(['restaurante_id', 'estado', 'fecha'], 'idx_res_estado');
            $table->index(['mesa_id', 'fecha', 'hora'], 'idx_res_mesa');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservas');
    }
};
