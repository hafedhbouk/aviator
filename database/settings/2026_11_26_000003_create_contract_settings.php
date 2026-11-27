<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/*
 * Contrats (D-08, Q14) : restitution anticipée sans remboursement, en attendant la
 * règle validée par la direction (D-08 est P2).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('contracts.early_return_refund', 'none'); // Q14
    }

    public function down(): void
    {
        $this->migrator->delete('contracts.early_return_refund');
    }
};
