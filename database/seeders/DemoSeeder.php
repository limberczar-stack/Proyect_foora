<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Mesa;
use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\Rubro;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración: dos restaurantes y sus usuarios, para probar login, roles
 * y el aislamiento entre locales. No se llama desde DatabaseSeeder.
 *
 * Uso: php artisan db:seed --class=DemoSeeder
 * Contraseña de todos los usuarios: password
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder no se ejecuta en producción (APP_ENV=production).');

            return;
        }

        // Catálogos (idempotentes): por si se ejecuta sin haber corrido DatabaseSeeder.
        $this->call([RubroSeeder::class, PlanSeeder::class, MetodoPagoSeeder::class]);

        $buenSabor = Restaurante::updateOrCreate(['slug' => 'el-buen-sabor'], [
            'rubro_id' => Rubro::where('nombre', 'Pollería')->value('id'),
            'plan_id' => Plan::where('nombre', 'Pro')->value('id'),
            'nombre' => 'Pollería El Buen Sabor',
            'telefono' => '962345678',
            'direccion' => 'Jr. Dos de Mayo 1020',
            'ciudad' => 'Huánuco',
            'estado_local' => 'abierto',
            'activo' => true,
        ]);

        $laBahia = Restaurante::updateOrCreate(['slug' => 'la-bahia'], [
            'rubro_id' => Rubro::where('nombre', 'Cevichería')->value('id'),
            'plan_id' => Plan::where('nombre', 'Básico')->value('id'),
            'nombre' => 'Cevichería La Bahía',
            'telefono' => '962876543',
            'direccion' => 'Av. Alameda de la República 455',
            'ciudad' => 'Huánuco',
            'estado_local' => 'cerrado',
            'activo' => true,
        ]);

        $this->usuario('superadmin@foora.test', 'Equipo FOORA', 'superadmin', null);
        $this->usuario('admin@foora.test', 'Rosa Quispe', 'admin', $buenSabor);
        $this->usuario('cajero@foora.test', 'Miguel Torres', 'cajero', $buenSabor);
        $this->usuario('admin@labahia.test', 'Carla Mendoza', 'admin', $laBahia);

        $this->mesas($buenSabor, [
            'Salón principal' => ['M1' => 4, 'M2' => 2, 'M3' => 4, 'M4' => 4, 'M5' => 2, 'M6' => 6, 'M7' => 6, 'M8' => 4, 'M9' => 4, 'M10' => 8],
            'Terraza' => ['T1' => 2, 'T2' => 2, 'T3' => 4, 'T4' => 4, 'T5' => 4, 'T6' => 2],
        ]);

        $this->mesas($laBahia, [
            'Salón' => ['B1' => 4, 'B2' => 4, 'B3' => 2, 'B4' => 6],
        ]);

        $this->command?->info('Demo lista. Usuarios: superadmin@foora.test, admin@foora.test, cajero@foora.test, admin@labahia.test (contraseña: password).');
    }

    private function usuario(string $email, string $nombre, string $rol, ?Restaurante $restaurante): User
    {
        return User::updateOrCreate(['email' => $email], [
            'name' => $nombre,
            'password' => 'password',
            'rol' => $rol,
            'restaurante_id' => $restaurante?->id,
            'activo' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, array<string, int>>  $areas  área => [código => capacidad]
     */
    private function mesas(Restaurante $restaurante, array $areas): void
    {
        $orden = 0;

        foreach ($areas as $nombreArea => $mesas) {
            $area = Area::updateOrCreate(
                ['restaurante_id' => $restaurante->id, 'nombre' => $nombreArea],
                ['activa' => true, 'orden' => $orden++],
            );

            foreach ($mesas as $codigo => $capacidad) {
                Mesa::updateOrCreate(
                    ['restaurante_id' => $restaurante->id, 'codigo' => $codigo],
                    ['area_id' => $area->id, 'capacidad' => $capacidad, 'estado' => 'disponible', 'activa' => true],
                );
            }
        }
    }
}
