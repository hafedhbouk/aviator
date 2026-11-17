<?php

namespace Database\Seeders;

use App\Models\Agency;
use Illuminate\Database\Seeder;

/**
 * Les 4 agences d'Aviator (données de référence, aucun compte).
 * Idempotent : peut être rejoué sans créer de doublon.
 */
class AgencySeeder extends Seeder
{
    public function run(): void
    {
        $agencies = [
            ['code' => 'DJE', 'name' => 'Djerba', 'city' => 'Djerba', 'is_head_office' => true],
            ['code' => 'SFX', 'name' => 'Sfax', 'city' => 'Sfax', 'is_head_office' => false],
            ['code' => 'SOU', 'name' => 'Sousse', 'city' => 'Sousse', 'is_head_office' => false],
            ['code' => 'TUN', 'name' => 'Tunis', 'city' => 'Tunis', 'is_head_office' => false],
        ];

        foreach ($agencies as $agency) {
            Agency::query()->updateOrCreate(['code' => $agency['code']], $agency);
        }
    }
}
