<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * État d'une caution (F-02), toujours calculé (Deposit::status()) : reçue (contrat en
 * cours), à restituer (contrat restitué, reste à rendre), restituée (rendue en totalité),
 * partiellement compensée (soldée avec une retenue au moins).
 */
enum DepositStatus: string
{
    case Received = 'received';
    case ToRefund = 'to_refund';
    case Refunded = 'refunded';
    case PartiallyOffset = 'partially_offset';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Reçue',
            self::ToRefund => 'À restituer',
            self::Refunded => 'Restituée',
            self::PartiallyOffset => 'Partiellement compensée',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Received || $this === self::ToRefund;
    }
}
