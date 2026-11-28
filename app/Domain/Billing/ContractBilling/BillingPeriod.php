<?php

declare(strict_types=1);

namespace App\Domain\Billing\ContractBilling;

use Carbon\CarbonImmutable;

/**
 * Période facturée d'un contrat (RG-05, Q50, D52) : rangs des jours facturés
 * [fromDay, toDay[ et dates de début et de fin. Un contrat de
 * `billing.long_rental_period_days` jours ou moins n'a qu'une période.
 */
final readonly class BillingPeriod
{
    public function __construct(
        public int $index,
        public int $fromDay,
        public int $toDay,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $isLast,
    ) {}

    public function days(): int
    {
        return $this->toDay - $this->fromDay;
    }
}
