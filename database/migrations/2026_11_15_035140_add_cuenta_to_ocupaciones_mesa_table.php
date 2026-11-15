<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ocupaciones_mesa pasa a ser "las mesas de una cuenta": una fila por mesa, y salida_at nulo
     * significa que la mesa sigue ocupada.
     *
     * - cuenta_id: nulo para una ocupación sin cuenta (reservas, más adelante).
     * - mesa_abierta_id (generada) vale mesa_id mientras la ocupación está abierta y NULL después;
     *   su índice único hace que la base garantice una sola ocupación abierta por mesa.
     * - personas y consumo_total dejan de ser obligatorias: comensales y totales viven en la cuenta
     *   y no se copian aquí.
     */
    public function up(): void
    {
        Schema::table('ocupaciones_mesa', function (Blueprint $table) {
            $table->foreignId('cuenta_id')->nullable()->after('mesa_id')->constrained('cuentas', indexName: 'fk_ocu_cuenta');
            $table->unsignedTinyInteger('personas')->nullable()->default(null)->change();
            $table->decimal('consumo_total', 10, 2)->nullable()->default(null)->change();
        });

        Schema::table('ocupaciones_mesa', function (Blueprint $table) {
            $table->unsignedBigInteger('mesa_abierta_id')->nullable()->virtualAs('CASE WHEN salida_at IS NULL THEN mesa_id END');
        });

        Schema::table('ocupaciones_mesa', function (Blueprint $table) {
            $table->unique('mesa_abierta_id', 'uq_ocu_mesa_abierta');
        });
    }

    /**
     * Las ocupaciones sin personas o sin consumo vuelven a sus valores por defecto de antes (1 y 0).
     */
    public function down(): void
    {
        Schema::table('ocupaciones_mesa', function (Blueprint $table) {
            $table->dropUnique('uq_ocu_mesa_abierta');
        });

        Schema::table('ocupaciones_mesa', function (Blueprint $table) {
            $table->dropColumn('mesa_abierta_id');
        });

        DB::table('ocupaciones_mesa')->whereNull('personas')->update(['personas' => 1]);
        DB::table('ocupaciones_mesa')->whereNull('consumo_total')->update(['consumo_total' => 0]);

        Schema::table('ocupaciones_mesa', function (Blueprint $table) {
            $table->dropForeign('fk_ocu_cuenta');
        });

        Schema::table('ocupaciones_mesa', function (Blueprint $table) {
            $table->dropColumn('cuenta_id');
            $table->unsignedTinyInteger('personas')->default(1)->change();
            $table->decimal('consumo_total', 10, 2)->default(0)->change();
        });
    }
};
