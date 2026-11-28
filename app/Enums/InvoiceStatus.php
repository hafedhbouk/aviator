<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut du document (E11, D56) : brouillon → validée (numéro attribué) → émise
 * (remise au client, PDF figé) → avoir total. « Annulée » seulement pour une facture
 * validée jamais émise, motif à l'appui, numéro conservé (Q79). Une facture émise
 * s'annule par un avoir (E-04, RG-06).
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Issued = 'issued';
    case Credited = 'credited';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Validated => 'Validée',
            self::Issued => 'Émise',
            self::Credited => 'Avoir total',
            self::Cancelled => 'Annulée',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Validated => 'info',
            self::Issued => 'success',
            self::Credited => 'warning',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Document verrouillé : tout statut autre que le brouillon (RG-06).
     */
    public function isLocked(): bool
    {
        return $this !== self::Draft;
    }

    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Validated],
            self::Validated => [self::Issued, self::Cancelled],
            self::Issued => [self::Credited],
            self::Credited, self::Cancelled => [],
        }, true);
    }
}
