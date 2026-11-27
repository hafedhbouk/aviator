<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/*
 * Documents du contrat (D-10, Q15, D43) : modèle de contrat non validé juridiquement
 * par défaut (mention « Modèle non validé » sur les PDF) ; conditions générales vides
 * tant que le super admin ne les a pas saisies.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('contracts.template_validated', false); // Q15
        $this->migrator->add('contracts.terms_text', null); // Q15
    }

    public function down(): void
    {
        $this->migrator->delete('contracts.template_validated');
        $this->migrator->delete('contracts.terms_text');
    }
};
