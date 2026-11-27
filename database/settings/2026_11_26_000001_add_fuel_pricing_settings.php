<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/*
 * Carburant manquant au retour (D-09, D50, Q44) : prix du litre et frais de service,
 * vides par défaut : aucun frais de carburant calculé tant qu'ils ne sont pas saisis.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('pricing.fuel_liter_amount', null); // Q44
        $this->migrator->add('pricing.fuel_service_amount', null); // Q44
    }

    public function down(): void
    {
        $this->migrator->delete('pricing.fuel_liter_amount');
        $this->migrator->delete('pricing.fuel_service_amount');
    }
};
