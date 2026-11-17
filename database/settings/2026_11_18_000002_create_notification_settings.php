<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // D20 : désactivé par défaut ; à activer si le cron ne tourne que toutes les 5 minutes.
        $this->migrator->add('notifications.send_urgent_in_request', false);
    }

    public function down(): void
    {
        $this->migrator->delete('notifications.send_urgent_in_request');
    }
};
