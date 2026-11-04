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
        Schema::create('restaurantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubro_id')->nullable()->constrained('rubros', indexName: 'fk_rest_rubro')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('planes', indexName: 'fk_rest_plan')->nullOnDelete();
            $table->string('nombre', 120);
            $table->string('slug', 140)->unique();
            $table->text('descripcion')->nullable();
            $table->string('logo')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('direccion')->nullable();
            $table->string('ciudad', 80)->default('Huánuco');
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->enum('estado_local', ['abierto', 'cerrado'])->default('cerrado');
            $table->boolean('visible_en_app')->default(true);
            $table->boolean('acepta_pedidos_auto')->default(false);
            $table->boolean('sonido_nuevos_pedidos')->default(true);
            $table->boolean('promociones_habilitadas')->default(false);
            $table->boolean('acepta_delivery')->default(true);
            $table->boolean('acepta_recojo')->default(true);
            $table->boolean('acepta_reservas')->default(true);
            $table->decimal('costo_delivery', 8, 2)->default(0);
            $table->decimal('pedido_minimo', 8, 2)->default(0);
            $table->unsignedSmallInteger('tiempo_prep_min')->nullable();
            $table->unsignedSmallInteger('tolerancia_reserva_min')->default(15);
            $table->unsignedTinyInteger('umbral_ocupacion_alta')->default(95);
            $table->char('moneda', 3)->default('PEN');
            $table->string('zona_horaria', 40)->default('America/Lima');
            $table->json('config_notificaciones')->nullable();
            $table->decimal('calificacion_promedio', 2, 1)->default(0);
            $table->unsignedInteger('total_resenas')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ciudad', 'visible_en_app', 'activo'], 'idx_rest_ciudad');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurantes');
    }
};
