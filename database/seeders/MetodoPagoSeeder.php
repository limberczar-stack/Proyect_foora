<?php

namespace Database\Seeders;

use App\Models\MetodoPago;
use Illuminate\Database\Seeder;

class MetodoPagoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $metodos = [
            ['codigo' => 'efectivo', 'nombre' => 'Efectivo', 'requiere_validacion' => false],
            ['codigo' => 'yape', 'nombre' => 'Yape', 'requiere_validacion' => true],
            ['codigo' => 'plin', 'nombre' => 'Plin', 'requiere_validacion' => true],
            ['codigo' => 'tarjeta', 'nombre' => 'Tarjeta (POS)', 'requiere_validacion' => false],
        ];

        foreach ($metodos as $metodo) {
            MetodoPago::updateOrCreate(['codigo' => $metodo['codigo']], $metodo);
        }
    }
}
