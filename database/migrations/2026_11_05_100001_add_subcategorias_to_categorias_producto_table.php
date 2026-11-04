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
        Schema::table('categorias_producto', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('restaurante_id')
                ->constrained('categorias_producto', indexName: 'fk_cat_parent')->restrictOnDelete();
            $table->string('descripcion')->nullable()->after('nombre');
            $table->boolean('con_fotos')->default(true)->after('activa');

            $table->index('parent_id', 'idx_cat_parent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categorias_producto', function (Blueprint $table) {
            $table->dropForeign('fk_cat_parent');
            $table->dropIndex('idx_cat_parent');
            $table->dropColumn(['parent_id', 'descripcion', 'con_fotos']);
        });
    }
};
