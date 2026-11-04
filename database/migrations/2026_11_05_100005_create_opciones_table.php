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
        Schema::create('opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_id')->constrained('grupos_opcion', indexName: 'fk_op_grupo')->cascadeOnDelete();
            $table->string('nombre', 80);
            $table->decimal('precio_extra', 8, 2)->default(0);
            $table->boolean('disponible')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['grupo_id', 'orden'], 'idx_op_grupo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opciones');
    }
};
