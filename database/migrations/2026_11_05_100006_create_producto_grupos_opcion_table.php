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
        Schema::create('producto_grupos_opcion', function (Blueprint $table) {
            $table->foreignId('producto_id')->constrained('productos', indexName: 'fk_pgo_prod')->cascadeOnDelete();
            $table->foreignId('grupo_id')->constrained('grupos_opcion', indexName: 'fk_pgo_grupo')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);

            $table->primary(['producto_id', 'grupo_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producto_grupos_opcion');
    }
};
