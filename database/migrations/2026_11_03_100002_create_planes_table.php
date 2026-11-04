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
        Schema::create('planes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 40)->unique();
            $table->decimal('precio_mensual', 8, 2);
            $table->unsignedSmallInteger('max_mesas')->nullable();
            $table->unsignedSmallInteger('max_usuarios')->nullable();
            $table->unsignedSmallInteger('max_productos')->nullable();
            $table->boolean('incluye_reservas')->default(true);
            $table->boolean('incluye_promociones')->default(false);
            $table->boolean('incluye_reportes')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planes');
    }
};
