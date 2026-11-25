<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Q28 : « Réservé » = réservation confirmée avec ce véhicule, départ dans les 24 h.
        $this->migrator->add('reservations.reserved_status_window_hours', 24);
    }

    public function down(): void
    {
        $this->migrator->delete('reservations.reserved_status_window_hours');
    }
};
