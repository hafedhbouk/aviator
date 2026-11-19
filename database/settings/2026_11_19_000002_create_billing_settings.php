<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Q86 : demi supérieur par défaut, à confirmer par le cabinet.
        $this->migrator->add('billing.vat_rounding_mode', 'half_up');
    }

    public function down(): void
    {
        $this->migrator->delete('billing.vat_rounding_mode');
    }
};
