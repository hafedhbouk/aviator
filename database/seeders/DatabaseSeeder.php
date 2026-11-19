<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Données de référence uniquement. Aucun compte utilisateur n'est créé ici (Q2) :
 * le super admin est créé par « php artisan aviator:create-super-admin ».
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AgencySeeder::class,
            FiscalReferenceSeeder::class,
            OptionSeeder::class,
            PaymentMethodSeeder::class,
        ]);
    }
}
