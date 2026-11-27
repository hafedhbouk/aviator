<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/*
 * États des lieux (D44) : 12 photos au plus par état des lieux.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('inspections.max_photos', 12); // D44
    }

    public function down(): void
    {
        $this->migrator->delete('inspections.max_photos');
    }
};
