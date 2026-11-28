<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut de paiement d'une facture (E11, D56) : toujours calculé depuis les
 * imputations des encaissements et des avoirs, jamais saisi.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Non payée',
            self::PartiallyPaid => 'Partiellement payée',
            self::Paid => 'Payée',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
        };
    }
}
