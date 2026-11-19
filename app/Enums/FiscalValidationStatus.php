<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut de validation d'une règle fiscale (référentiel v1.0, §14) :
 *
 * - CONFIRMED : validée et documentée, applicable ;
 * - CONDITIONAL : applicable selon la nature réelle de l'opération, avec ses
 *   justificatifs (exigés ligne par ligne en phase 6) ;
 * - PENDING_DOCUMENTATION : pièce ou confirmation manquante, jamais applicable.
 */
enum FiscalValidationStatus: string
{
    case Confirmed = 'CONFIRMED';
    case Conditional = 'CONDITIONAL';
    case PendingDocumentation = 'PENDING_DOCUMENTATION';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmée',
            self::Conditional => 'Conditionnelle',
            self::PendingDocumentation => 'Pièce attendue',
        };
    }

    public function isApplicable(): bool
    {
        return $this !== self::PendingDocumentation;
    }
}
