<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Seul point de décision sur l'accès aux données clients (RG-14, D13). Appelé par
 * les policies, puis par les écrans, les exports et les statistiques.
 *
 * Carnet d'adresses = coordonnées des clients en liste et tout export de fichier
 * clients : super admin. L'admin voit les noms dans les listes métier mais n'exporte
 * que des agrégats, sauf si la permission customers.export_names lui est accordée
 * (« aviator:permission grant admin customers.export_names », D17), sans changer le code.
 *
 * Tout refus est inscrit dans le journal customer_access.
 */
final class CustomerDataAccess
{
    public function canViewAddressBook(User $user): bool
    {
        return $this->allowOrRecord($user, Permission::CustomersAddressBook, 'address_book');
    }

    public function canExportCustomerFile(User $user): bool
    {
        return $this->allowOrRecord($user, Permission::CustomersExport, 'customer_file_export');
    }

    /**
     * Noms des clients dans les listes métier. Un utilisateur d'agence n'y a accès que
     * pour les documents de ses agences autorisées.
     */
    public function canViewNames(User $user, ?int $agencyId = null): bool
    {
        if (! $user->checkPermissionTo(Permission::CustomersViewNames->value)) {
            $this->recordRefusal($user, 'view_names', ['agency_id' => $agencyId]);

            return false;
        }

        if ($user->canActOnAllAgencies()) {
            return true;
        }

        if ($agencyId === null || ! $user->canActOnAgency($agencyId)) {
            $this->recordRefusal($user, 'view_names', ['agency_id' => $agencyId]);

            return false;
        }

        return true;
    }

    public function canNotifyCustomersInBulk(User $user): bool
    {
        return $this->allowOrRecord($user, Permission::CustomersNotifyBulk, 'bulk_notification');
    }

    /**
     * Colonnes client autorisées dans un export de cette nature.
     */
    public function exportLevel(User $user, ExportKind $kind): ExportLevel
    {
        $level = match ($kind) {
            ExportKind::CustomerFile => $this->has($user, Permission::CustomersExport)
                ? ExportLevel::Full
                : ExportLevel::Denied,

            ExportKind::Accounting => $this->has($user, Permission::ExportsAccounting)
                ? ExportLevel::Accounting
                : ExportLevel::Denied,

            ExportKind::BusinessList, ExportKind::Statistics => match (true) {
                $this->has($user, Permission::CustomersExport) => ExportLevel::Full,
                $this->has($user, Permission::CustomersExportNames) => ExportLevel::Names,
                $user->canActOnAllAgencies() => ExportLevel::Aggregates,
                default => ExportLevel::Denied,
            },
        };

        if ($level === ExportLevel::Denied) {
            $this->recordRefusal($user, 'export', ['kind' => $kind->value]);
        }

        return $level;
    }

    /**
     * Consultation ou export du carnet d'adresses par un profil autorisé : toujours journalisé (K-03).
     *
     * @param  array<string, mixed>  $context
     */
    public function recordAddressBookUse(User $user, string $event, array $context = []): void
    {
        activity('customer_access')
            ->causedBy($user)
            ->event($event)
            ->withProperties($context)
            ->log('Utilisation du carnet d\'adresses');
    }

    private function has(User $user, Permission $permission): bool
    {
        return $user->checkPermissionTo($permission->value);
    }

    private function allowOrRecord(User $user, Permission $permission, string $ability): bool
    {
        if ($this->has($user, $permission)) {
            return true;
        }

        $this->recordRefusal($user, $ability);

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordRefusal(User $user, string $ability, array $context = []): void
    {
        activity('customer_access')
            ->causedBy($user)
            ->event('access_denied')
            ->withProperties(['ability' => $ability, 'profile' => $user->profile()?->value] + $context)
            ->log('Accès refusé aux données clients');
    }
}
