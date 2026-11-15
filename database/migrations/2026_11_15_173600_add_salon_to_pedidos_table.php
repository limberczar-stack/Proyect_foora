<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedidos de salón (fase B2): cada ronda de una cuenta es un pedido tipo salon.
     *
     * - nombre_cliente, telefono_cliente y metodo_pago_id dejan de ser obligatorios en la base:
     *   una ronda no tiene cliente ni método de pago (se cobra por cuenta). Los pedidos de la
     *   vitrina los siguen exigiendo en PedidoRequest y CrearPedido.
     * - cuenta_id y ronda: a qué cuenta pertenece y en qué orden se envió. Único por cuenta; los
     *   pedidos de delivery y recojo quedan con ambos en NULL y no chocan.
     * - Quién envió la ronda va en creado_por (ya existía). mesa_id no se usa: una ronda puede ser
     *   de varias mesas, que salen de la cuenta.
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('nombre_cliente', 120)->nullable()->change();
            $table->string('telefono_cliente', 20)->nullable()->change();
            $table->unsignedBigInteger('metodo_pago_id')->nullable()->change();
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreignId('cuenta_id')->nullable()->after('mesa_id')->constrained('cuentas', indexName: 'fk_ped_cuenta');
            $table->unsignedSmallInteger('ronda')->nullable()->after('cuenta_id');
            $table->unique(['cuenta_id', 'ronda'], 'uq_ped_ronda');
        });
    }

    /**
     * No se puede volver atrás con pedidos de salón guardados: habría que inventar un cliente y un
     * método de pago para cada ronda. En ese caso se detiene sin tocar nada.
     */
    public function down(): void
    {
        $sinDatos = DB::table('pedidos')
            ->where(fn ($query) => $query->whereNull('nombre_cliente')->orWhereNull('telefono_cliente')->orWhereNull('metodo_pago_id'))
            ->count();

        if ($sinDatos > 0) {
            throw new RuntimeException("Hay {$sinDatos} pedidos sin cliente o sin método de pago (pedidos de salón). No se puede revertir esta migración sin inventar esos datos.");
        }

        // MySQL usa uq_ped_ronda como índice de la FK de cuenta_id: primero se quita la FK.
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropForeign('fk_ped_cuenta');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropUnique('uq_ped_ronda');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['cuenta_id', 'ronda']);
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('nombre_cliente', 120)->nullable(false)->change();
            $table->string('telefono_cliente', 20)->nullable(false)->change();
            $table->unsignedBigInteger('metodo_pago_id')->nullable(false)->change();
        });
    }
};
