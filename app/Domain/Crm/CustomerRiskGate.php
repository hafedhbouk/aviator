<?php

declare(strict_types=1);

namespace App\Domain\Crm;

use App\Domain\Crm\Exceptions\CustomerBlocked;
use App\Enums\RecommendationLevel;
use App\Enums\RecommendationStatus;
use App\Models\Customer;
use App\Models\CustomerRecommendation;

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
