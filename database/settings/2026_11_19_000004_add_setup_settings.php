<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/*
 * Réglages du paramétrage (A-06, A-09, approbations), valeurs par défaut des
 * questions ouvertes. Une valeur vide (null) = contrainte ou alerte désactivée, en
 * attendant la décision de la direction (Q90).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        // A-06 : règles de réservation.
        $this->migrator->add('reservations.min_rental_days', null); // Q90
        $this->migrator->add('reservations.min_notice_hours', null); // Q90
        $this->migrator->add('reservations.draft_expiry_hours', null); // Q90, C-09
        $this->migrator->add('reservations.no_show_after_minutes', 120); // Q39
        $this->migrator->add('reservations.allow_one_way', false); // Q3

        // Tarification : décompte des jours et retards.
        $this->migrator->add('pricing.day_mode', 'rolling_24h'); // Q32
        $this->migrator->add('pricing.late_tolerance_minutes', 60); // Q14
        $this->migrator->add('pricing.late_unit', 'day'); // Q14

        // Approbations du super admin.
        $this->migrator->add('approvals.discount_threshold_percent', '0'); // Q10, Q52
        $this->migrator->add('approvals.delegate_to_admin', false); // Q52

        // A-09 : alertes (événements, destinataires, délais), envoyées en phase 9.
        $this->migrator->add('notifications.alerts', [
            'insurance_expiry' => ['enabled' => false, 'days_before' => null, 'recipients' => ['admin']],
            'technical_inspection' => ['enabled' => false, 'days_before' => null, 'recipients' => ['admin']],
            'maintenance_due' => ['enabled' => false, 'days_before' => null, 'recipients' => ['admin']],
            'leasing_end' => ['enabled' => false, 'days_before' => null, 'recipients' => ['admin']],
            'late_return' => ['enabled' => true, 'days_before' => null, 'recipients' => ['admin', 'agency_user']],
            'approval_request' => ['enabled' => true, 'days_before' => null, 'recipients' => ['super_admin']],
        ]);
    }

    public function down(): void
    {
        foreach ([
            'reservations.min_rental_days', 'reservations.min_notice_hours', 'reservations.draft_expiry_hours',
            'reservations.no_show_after_minutes', 'reservations.allow_one_way',
            'pricing.day_mode', 'pricing.late_tolerance_minutes', 'pricing.late_unit',
            'approvals.discount_threshold_percent', 'approvals.delegate_to_admin',
            'notifications.alerts',
        ] as $key) {
            $this->migrator->delete($key);
        }
    }
};
