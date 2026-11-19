<?php

declare(strict_types=1);

namespace App\Support\Authorization;

/**
 * Permissions de l'application, déclarées dans le code (D16) et synchronisées par
 * « php artisan aviator:sync-permissions ». Les phases suivantes ajouteront les
 * permissions métier ; la matrice complète est dans docs/architecture.md §8.1.
 */
enum Permission: string
{
    case UsersManage = 'users.manage';
    case AuditView = 'audit.view';
    case AgenciesAll = 'agencies.all';

    // Données clients (RG-14, D13).
    case CustomersAddressBook = 'customers.address_book';
    case CustomersExport = 'customers.export';
    case CustomersExportNames = 'customers.export_names';
    case CustomersViewNames = 'customers.view_names';
    case CustomersNotifyBulk = 'customers.notify_bulk';

    case ExportsAccounting = 'exports.accounting';

    // Paramétrage (module A, phase 3).
    case ReferenceDataManage = 'reference_data.manage';
    case FiscalManage = 'fiscal.manage';
    case SettingsManage = 'settings.manage';

    /**
     * Profils qui reçoivent cette permission par défaut.
     *
     * @return list<Role>
     */
    public function defaultRoles(): array
    {
        return match ($this) {
            self::UsersManage,
            self::AuditView,
            self::CustomersAddressBook,
            self::CustomersExport,
            self::CustomersExportNames,
            self::CustomersNotifyBulk,
            self::FiscalManage,
            self::SettingsManage => [Role::SuperAdmin],

            self::AgenciesAll,
            self::ExportsAccounting,
            self::ReferenceDataManage => [Role::SuperAdmin, Role::Admin],

            self::CustomersViewNames => [Role::SuperAdmin, Role::Admin, Role::AgencyUser],
        };
    }

    /**
     * Profils auxquels la permission peut être accordée sans changer le code
     * (« aviator:permission grant », D17). Vide = non assouplissable.
     *
     * @return list<Role>
     */
    public function relaxableFor(): array
    {
        return match ($this) {
            self::CustomersExportNames => [Role::Admin],
            default => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::UsersManage => 'Gérer les utilisateurs et leurs droits',
            self::AuditView => 'Consulter le journal d\'audit',
            self::AgenciesAll => 'Agir sur toutes les agences',
            self::CustomersAddressBook => 'Consulter le carnet d\'adresses (coordonnées en liste)',
            self::CustomersExport => 'Exporter un fichier clients complet',
            self::CustomersExportNames => 'Exporter des listes métier avec les noms des clients',
            self::CustomersViewNames => 'Voir les noms des clients dans les listes métier',
            self::CustomersNotifyBulk => 'Envoyer des notifications groupées aux clients',
            self::ExportsAccounting => 'Produire les exports pour l\'expert-comptable',
            self::ReferenceDataManage => 'Modifier le paramétrage (société, agences, catégories, tarifs, options, fournisseurs)',
            self::FiscalManage => 'Modifier les paramètres fiscaux et les mentions des factures',
            self::SettingsManage => 'Modifier les réglages (réservation, tarification, facturation, approbations, notifications, sécurité)',
        };
    }
}
