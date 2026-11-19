<?php

declare(strict_types=1);

namespace App\Domain\Tax\Exceptions;

use RuntimeException;

/**
 * Aucune règle fiscale applicable : le calcul est refusé, jamais fait à 0 %.
 */
final class TaxRuleUnavailable extends RuntimeException
{
    public static function unknownCode(string $code): self
    {
        return new self("Code fiscal inconnu : {$code}. Choisissez un code du référentiel validé.");
    }

    public static function noRule(string $code, string $date): self
    {
        return new self("Aucune règle fiscale active pour {$code} au {$date}.");
    }

    public static function pendingDocumentation(string $code): self
    {
        return new self("La règle de {$code} attend une pièce (PENDING_DOCUMENTATION) : elle ne s'applique pas.");
    }

    public static function noStampRule(string $date): self
    {
        return new self("Aucune règle de timbre active au {$date}.");
    }

    public static function noLines(): self
    {
        return new self('Aucune ligne à calculer.');
    }
}
