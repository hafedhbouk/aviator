<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/*
 * Q4 : un utilisateur d'agence voit le téléphone du client sur un contrat en cours de
 * son agence, pour le joindre en cas de retard ; chaque consultation est journalisée.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('contracts.show_phone_on_active_contract', true); // Q4
    }

    public function down(): void
    {
        $this->migrator->delete('contracts.show_phone_on_active_contract');
    }
};
