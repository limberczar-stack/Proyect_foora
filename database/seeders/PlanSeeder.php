<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Ajusta qué incluye cada plan: el PPT define los precios, no las diferencias entre planes.
     */
    public function run(): void
    {
        $planes = [
            ['nombre' => 'Básico', 'precio_mensual' => 39.00, 'incluye_reservas' => true, 'incluye_promociones' => false, 'incluye_reportes' => false],
            ['nombre' => 'Pro', 'precio_mensual' => 79.00, 'incluye_reservas' => true, 'incluye_promociones' => true, 'incluye_reportes' => false],
            ['nombre' => 'Premium', 'precio_mensual' => 129.00, 'incluye_reservas' => true, 'incluye_promociones' => true, 'incluye_reportes' => true],
        ];

        foreach ($planes as $plan) {
            Plan::updateOrCreate(['nombre' => $plan['nombre']], $plan);
        }
    }
}
