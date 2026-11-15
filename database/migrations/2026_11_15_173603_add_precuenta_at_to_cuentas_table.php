<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuándo se pidió la última precuenta. Si llega otra ronda la cuenta vuelve a abierta,
     * pero este dato se conserva hasta la siguiente precuenta.
     */
    public function up(): void
    {
        Schema::table('cuentas', function (Blueprint $table) {
            $table->timestamp('precuenta_at')->nullable()->after('abierta_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cuentas', function (Blueprint $table) {
            $table->dropColumn('precuenta_at');
        });
    }
};
