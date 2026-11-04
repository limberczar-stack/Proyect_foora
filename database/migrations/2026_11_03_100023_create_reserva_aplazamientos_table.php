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
        Schema::create('reserva_aplazamientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_id')->constrained('reservas', indexName: 'fk_apl_res')->cascadeOnDelete();
            $table->time('hora_original');
            $table->time('hora_solicitada');
            $table->string('motivo')->nullable();
            $table->enum('estado', ['pendiente', 'aprobada', 'rechazada'])->default('pendiente');
            $table->foreignId('resuelta_por')->nullable()->constrained('users', indexName: 'fk_apl_user')->nullOnDelete();
            $table->timestamp('resuelta_at')->nullable();
            $table->timestamps();

            $table->index(['estado', 'created_at'], 'idx_apl_estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reserva_aplazamientos');
    }
};
