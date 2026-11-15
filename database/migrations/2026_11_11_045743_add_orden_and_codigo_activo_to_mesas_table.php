<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mesas para la gestión del salón (fase A):
     * - orden: posición de la mesa dentro de su área.
     * - codigo pasa a 20 caracteres ("Barra alta 2").
     * - El código es único solo entre las mesas activas. MySQL no tiene índices parciales, así que
     *   codigo_activo (generada) vale el código si la mesa está activa y NULL si no; los NULL no chocan.
     */
    public function up(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->string('codigo', 20)->change();
            $table->unsignedSmallInteger('orden')->default(0)->after('capacidad');
        });

        Schema::table('mesas', function (Blueprint $table) {
            $table->string('codigo_activo', 20)->nullable()->virtualAs('CASE WHEN activa = 1 THEN codigo END');
        });

        Schema::table('mesas', function (Blueprint $table) {
            $table->unique(['restaurante_id', 'codigo_activo'], 'uq_mesa_activa');
        });

        Schema::table('mesas', function (Blueprint $table) {
            $table->dropUnique('uq_mesa');
        });
    }

    /**
     * Falla si hay códigos de más de 10 caracteres o códigos repetidos entre mesas desactivadas:
     * en ese caso hay que corregir los datos antes de volver atrás.
     */
    public function down(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->unique(['restaurante_id', 'codigo'], 'uq_mesa');
        });

        Schema::table('mesas', function (Blueprint $table) {
            $table->dropUnique('uq_mesa_activa');
        });

        Schema::table('mesas', function (Blueprint $table) {
            $table->dropColumn(['codigo_activo', 'orden']);
        });

        Schema::table('mesas', function (Blueprint $table) {
            $table->string('codigo', 10)->change();
        });
    }
};
