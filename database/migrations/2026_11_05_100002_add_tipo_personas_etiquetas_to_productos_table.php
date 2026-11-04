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
        Schema::table('productos', function (Blueprint $table) {
            $table->enum('tipo', ['simple', 'variantes', 'combo'])->default('simple')->after('categoria_id');
            $table->unsignedTinyInteger('personas')->nullable()->after('tipo');
            $table->json('etiquetas')->nullable()->after('estacion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'personas', 'etiquetas']);
        });
    }
};
