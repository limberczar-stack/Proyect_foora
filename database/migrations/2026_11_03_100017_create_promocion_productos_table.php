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
        Schema::create('promocion_productos', function (Blueprint $table) {
            $table->foreignId('promocion_id')->constrained('promociones', indexName: 'fk_pp_promo')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos', indexName: 'fk_pp_prod')->cascadeOnDelete();

            $table->primary(['promocion_id', 'producto_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promocion_productos');
    }
};
