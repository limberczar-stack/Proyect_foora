<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los pagos nunca se borran: anularlos es una marca. Cada pago guarda cómo estaba la suscripción
     * antes de registrarlo, para poder dejarla igual si se anula.
     */
    public function up(): void
    {
        Schema::table('suscripcion_pagos', function (Blueprint $table) {
            $table->foreignId('registrado_por')->nullable()->after('pagado_at')->constrained('users', indexName: 'fk_spag_registra')->nullOnDelete();
            $table->date('vence_antes')->nullable()->after('registrado_por');
            $table->string('estado_antes', 20)->nullable()->after('vence_antes');
            $table->decimal('precio_antes', 8, 2)->nullable()->after('estado_antes');
            $table->timestamp('anulado_at')->nullable()->after('precio_antes');
            $table->foreignId('anulado_por')->nullable()->after('anulado_at')->constrained('users', indexName: 'fk_spag_anula')->nullOnDelete();
            $table->string('motivo_anulacion')->nullable()->after('anulado_por');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suscripcion_pagos', function (Blueprint $table) {
            $table->dropForeign('fk_spag_registra');
            $table->dropForeign('fk_spag_anula');
            $table->dropColumn(['registrado_por', 'vence_antes', 'estado_antes', 'precio_antes', 'anulado_at', 'anulado_por', 'motivo_anulacion']);
        });
    }
};
