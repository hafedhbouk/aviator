<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\InvoiceValidationException;
use DateTimeInterface;

/**
 * Garde de période clôturée (RG-12, D53) : appelé par la validation et l'émission des
 * factures, puis par les avoirs et les encaissements (lots L6, L7). Les périodes
 * exportées et clôturées arrivent en phase 8 (exports comptables) : d'ici là, aucune
 * période n'est clôturée et le garde ne bloque rien. La phase 8 remplit `isClosed()`
 * sans toucher aux appelants.
 */
class ClosedPeriodGuard
{
    /**
     * @throws InvoiceValidationException période clôturée
     */
    public function assertOpen(DateTimeInterface $date, string $operation): void
    {
        if ($this->isClosed($date)) {
            throw InvoiceValidationException::closedPeriod($operation, $date->format('m/Y'));
        }
    }

    public function isClosed(DateTimeInterface $date): bool
    {
        return false;
    }
}
