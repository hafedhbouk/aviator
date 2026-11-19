<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Conditions de paiement (A-07) : 0 jour = paiement comptant, valeur par défaut à confirmer (Q89).
        $this->migrator->add('billing.payment_terms_days', 0);
    }

    public function down(): void
    {
        $this->migrator->delete('billing.payment_terms_days');
    }
};
