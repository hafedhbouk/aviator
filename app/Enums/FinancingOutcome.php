<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fin d'un financement (B-14) : levée de l'option d'achat (le véhicule reste au parc),
 * restitution (le véhicule sort du parc), remboursement d'un crédit.
 */
enum FinancingOutcome: string
{
    case OptionExercised = 'option_exercised';
    case Returned = 'returned';
    case Repaid = 'repaid';

    public function label(): string
    {
        return match ($this) {
            self::OptionExercised => 'Option d\'achat levée',
            self::Returned => 'Véhicule restitué',
            self::Repaid => 'Crédit remboursé',
        };
    }
}
