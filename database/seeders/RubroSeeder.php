<?php

namespace Database\Seeders;

use App\Models\Rubro;
use Illuminate\Database\Seeder;

class RubroSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['Pollería', 'Cevichería', 'Cafetería', 'Fast food', 'Chifa'] as $nombre) {
            Rubro::updateOrCreate(['nombre' => $nombre]);
        }
    }
}
