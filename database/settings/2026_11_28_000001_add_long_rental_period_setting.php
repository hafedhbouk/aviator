<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Location longue facturée par période (Q50, D52) : 30 jours, valeur par défaut à confirmer.
        $this->migrator->add('billing.long_rental_period_days', 30);
    }

    public function down(): void
    {
        $this->migrator->delete('billing.long_rental_period_days');
    }
};
