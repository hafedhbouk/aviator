<?php

declare(strict_types=1);

namespace App\Domain\Billing\ContractBilling;

use App\Enums\InvoiceLineNature;
use App\Support\Money\Money;

/**
 * Élément facturable d'un contrat (L3) : location, option, prolongation ou frais, avec
 * son montant brut, sa part de remise et les jours facturés qu'il couvre
 * ([fromDay, toDay[, rangs des jours du contrat). Un frais (`finalOnly`) n'a pas de
 * jours : il va sur la dernière période. Le code fiscal est seulement **proposé**
 * (D54) : aucun pour les frais.
 */
final readonly class BillableItem
{
    /**
     * @param  list<Money>|null  $dailyAmounts  montant exact de chaque jour couvert (location au tarif du devis), sinon prorata
     */
    public function __construct(
        public InvoiceLineNature $nature,
        public string $sourceType,
        public int $sourceId,
        public ?string $sourceKey,
        public string $label,
        public int $fromDay,
        public int $toDay,
        public Money $gross,
        public Money $discount,
        public string $quantityPerDay,
        public bool $perDay,
        public ?Money $unitAmount,
        public ?int $proposedTaxCodeId,
        public ?array $dailyAmounts = null,
        public bool $finalOnly = false,
    ) {}

    public function key(): string
    {
        return self::keyOf($this->sourceType, $this->sourceId, $this->sourceKey);
    }

    public static function keyOf(string $sourceType, int $sourceId, ?string $sourceKey): string
    {
        return $sourceType.':'.$sourceId.':'.($sourceKey ?? '');
    }

    public function days(): int
    {
        return $this->toDay - $this->fromDay;
    }

    /** Jours de l'élément compris dans [from, to[. */
    public function overlap(int $from, int $to): int
    {
        return max(0, min($this->toDay, $to) - max($this->fromDay, $from));
    }
}
