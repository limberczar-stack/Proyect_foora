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
        Schema::table('pedido_items', function (Blueprint $table) {
            $table->foreignId('variante_id')->nullable()->after('producto_id')
                ->constrained('producto_variantes', indexName: 'fk_item_var')->nullOnDelete();
            $table->string('variante_nombre', 60)->nullable()->after('variante_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pedido_items', function (Blueprint $table) {
            $table->dropForeign('fk_item_var');
            $table->dropColumn(['variante_id', 'variante_nombre']);
        });
    }
};
