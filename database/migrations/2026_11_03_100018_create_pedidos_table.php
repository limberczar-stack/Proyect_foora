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
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained('restaurantes', indexName: 'fk_ped_rest')->cascadeOnDelete();
            $table->unsignedInteger('numero');
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: 'fk_ped_user')->nullOnDelete();
            $table->string('nombre_cliente', 120);
            $table->string('telefono_cliente', 20);
            $table->enum('tipo', ['delivery', 'recojo', 'salon']);
            $table->enum('origen', ['app', 'local', 'telefono'])->default('app');
            $table->foreignId('mesa_id')->nullable()->constrained('mesas', indexName: 'fk_ped_mesa')->nullOnDelete();

            $table->string('direccion_entrega')->nullable();
            $table->string('referencia_entrega')->nullable();
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->foreignId('repartidor_id')->nullable()->constrained('repartidores', indexName: 'fk_ped_rep')->nullOnDelete();

            $table->foreignId('metodo_pago_id')->constrained('metodos_pago', indexName: 'fk_ped_pago');
            $table->enum('estado_pago', ['pendiente', 'por_verificar', 'pagado', 'rechazado'])->default('pendiente');
            $table->string('referencia_pago', 50)->nullable();
            $table->foreignId('pago_validado_por')->nullable()->constrained('users', indexName: 'fk_ped_val')->nullOnDelete();
            $table->timestamp('pago_validado_at')->nullable();

            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->decimal('costo_delivery', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->foreignId('promocion_id')->nullable()->constrained('promociones', indexName: 'fk_ped_promo')->nullOnDelete();
            $table->string('observaciones', 500)->nullable();

            $table->enum('estado', ['nuevo', 'en_preparacion', 'listo', 'en_camino', 'entregado', 'cancelado'])->default('nuevo');
            $table->unsignedSmallInteger('tiempo_estimado_min')->nullable();
            $table->timestamp('aceptado_at')->nullable();
            $table->timestamp('listo_at')->nullable();
            $table->timestamp('salida_at')->nullable();
            $table->timestamp('eta_at')->nullable();
            $table->timestamp('recoger_antes_de')->nullable();
            $table->timestamp('entregado_at')->nullable();

            $table->timestamp('cancelado_at')->nullable();
            $table->enum('cancelacion_motivo', [
                'cliente_no_respondio', 'cliente_cancelo', 'pago_no_confirmado',
                'cliente_no_llego', 'tiempo_espera_excedido', 'problema_delivery',
                'restaurante_rechazo', 'otro',
            ])->nullable();
            $table->string('cancelacion_detalle')->nullable();
            $table->boolean('registrado_como_perdida')->default(false);
            $table->foreignId('creado_por')->nullable()->constrained('users', indexName: 'fk_ped_crea')->nullOnDelete();
            $table->timestamps();

            $table->unique(['restaurante_id', 'numero'], 'uq_pedido_num');
            $table->index(['restaurante_id', 'estado', 'created_at'], 'idx_ped_estado');
            $table->index(['restaurante_id', 'created_at'], 'idx_ped_fecha');
            $table->index('user_id', 'idx_ped_cliente');
            $table->index(['restaurante_id', 'telefono_cliente'], 'idx_ped_tel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
