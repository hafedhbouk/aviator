<?php

declare(strict_types=1);

namespace App\Domain\Crm;

use App\Domain\Crm\Exceptions\CustomerBlocked;
use App\Enums\RecommendationLevel;
use App\Enums\RecommendationStatus;
use App\Models\Customer;
use App\Models\CustomerRecommendation;
use App\Models\Driver;

/**
 * Niveau de risque d'un client, valable dans **toutes** les agences (K-10, K-11,
 * RG-15, scénario 14) : celui de sa dernière recommandation effective. La création
 * d'une réservation (lot L11) et d'un contrat (phase 5) appellent assertCanRent().
 */
final class CustomerRiskGate
{
    public function levelOf(Customer $customer): ?RecommendationLevel
    {
        return CustomerRecommendation::query()
            ->where('customer_id', $customer->id)
            ->where('status', RecommendationStatus::Effective->value)
            ->latest('created_at')->latest('id')
            ->first()?->level;
    }

    public function isBlacklisted(Customer $customer): bool
    {
        return $this->levelOf($customer) === RecommendationLevel::Blacklist;
    }

    /**
     * Client à surveiller : bandeau d'alerte au comptoir (parcours P1), sans blocage.
     */
    public function isWatched(Customer $customer): bool
    {
        return $this->levelOf($customer) === RecommendationLevel::Watch;
    }

    /**
     * Conducteur rattaché à un client sur liste noire (Q48), autre que `$except` : même
     * permis ou même pièce d'identité qu'un conducteur ou une fiche client sur liste
     * noire, par empreinte (jamais par le nom).
     */
    public function isDriverBlacklisted(Driver $driver, ?int $except = null): bool
    {
        $customerIds = Driver::query()
            ->where('license_hash', $driver->license_hash)
            ->when($driver->id_document_hash !== null, fn ($query) => $query->orWhere('id_document_hash', $driver->id_document_hash))
            ->pluck('customer_id');

        if ($driver->id_document_hash !== null) {
            $customerIds = $customerIds->merge(Customer::query()->withoutGlobalScopes()->where('id_document_hash', $driver->id_document_hash)->pluck('id'));
        }

        return Customer::query()->withoutGlobalScopes()
            ->whereIn('id', $customerIds->push($driver->customer_id)->unique()->reject(fn ($id): bool => $id === $except)->values())
            ->get()
            ->contains(fn (Customer $customer): bool => $this->isBlacklisted($customer));
    }

    /**
     * @throws CustomerBlocked client sur liste noire, sans motif (D36 : aucune
     *                         dérogation avant la phase 5)
     */
    public function assertCanRent(Customer $customer): void
    {
        if ($this->isBlacklisted($customer)) {
            throw CustomerBlocked::blacklisted();
        }
    }
}
