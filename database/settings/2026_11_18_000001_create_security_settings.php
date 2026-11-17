<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Q76 : désactivé par défaut, en attente de la décision de la direction.
        $this->migrator->add('security.require_2fa_for_agency_users', false);
    }

    public function down(): void
    {
        $this->migrator->delete('security.require_2fa_for_agency_users');
    }
};
