<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('codigo_seguimiento', 40)->nullable()->after('numero');
            $table->string('idempotency_key', 64)->nullable()->after('codigo_seguimiento');
            $table->timestamp('consentimiento_at')->nullable()->after('creado_por');
            $table->string('consentimiento_version', 20)->nullable()->after('consentimiento_at');
        });

        DB::table('pedidos')->whereNull('codigo_seguimiento')->orderBy('id')->each(function (object $pedido): void {
            DB::table('pedidos')->where('id', $pedido->id)->update(['codigo_seguimiento' => Str::random(32)]);
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('codigo_seguimiento', 40)->nullable(false)->change();
            $table->unique('codigo_seguimiento', 'uq_ped_seguimiento');
            $table->unique(['restaurante_id', 'idempotency_key'], 'uq_ped_idempotencia');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropUnique('uq_ped_seguimiento');
            $table->dropUnique('uq_ped_idempotencia');
            $table->dropColumn(['codigo_seguimiento', 'idempotency_key', 'consentimiento_at', 'consentimiento_version']);
        });
    }
};
