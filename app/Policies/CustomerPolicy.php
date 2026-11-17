<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Support\Privacy\CustomerDataAccess;

/**
 * Clients (RG-14, D13). Toutes les décisions passent par CustomerDataAccess, qui
 * journalise les refus. La fiche d'un client pour l'admin et les agences (accès
 * temporaire après recherche exacte, D11) arrive en phase 7.
 */
class CustomerPolicy
{
    public function __construct(private readonly CustomerDataAccess $access) {}

    /**
     * Liste des clients = carnet d'adresses : super admin uniquement.
     */
    public function viewAny(User $user): bool
    {
        return $this->access->canViewAddressBook($user);
    }

    /**
     * Fiche complète d'un client. En phase 2 : profils ayant le carnet d'adresses ;
     * l'accès temporaire après recherche exacte (D11, Q64) est ajouté en phase 7.
     */
    public function view(User $user, Customer $customer): bool
    {
        return $this->access->canViewAddressBook($user);
    }

    /**
     * Export d'un fichier clients, depuis n'importe quel écran.
     */
    public function export(User $user): bool
    {
        return $this->access->canExportCustomerFile($user);
    }

    /**
     * Un client n'est jamais supprimé : il est anonymisé (L-05, Q20).
     */
    public function delete(User $user, Customer $customer): bool
    {
        return false;
    }

    public function forceDelete(User $user, Customer $customer): bool
    {
        return false;
    }
}
