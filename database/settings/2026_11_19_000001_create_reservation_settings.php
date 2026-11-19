<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Valeurs par défaut des questions ouvertes, surchargeables par catégorie.
        $this->migrator->add('reservations.preparation_buffer_minutes', 60); // Q41
        $this->migrator->add('reservations.min_driver_age', 21); // Q42
        $this->migrator->add('reservations.min_license_years', 2); // Q42
    }

    public function down(): void
    {
        $this->migrator->delete('reservations.preparation_buffer_minutes');
        $this->migrator->delete('reservations.min_driver_age');
        $this->migrator->delete('reservations.min_license_years');
    }
};
