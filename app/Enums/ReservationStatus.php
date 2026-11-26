<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une réservation (C-08, Q31). Au lot 1 : brouillon (devis enregistré, sans
 * véhicule ni capacité retenus) et confirmée ; « pending » attend la validation de
 * l'agence au lot 2 (RG-21) ; « converted » arrive avec les contrats (phase 5).
 */
enum ReservationStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case NoShow = 'no_show';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Pending => 'En attente',
            self::Confirmed => 'Confirmée',
            self::Cancelled => 'Annulée',
            self::Expired => 'Expirée',
            self::NoShow => 'Non présenté',
            self::Converted => 'Convertie en contrat',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::Cancelled, self::Expired, self::NoShow => 'danger',
            self::Converted => 'info',
        };
    }

    /**
     * Réservation qui retient un véhicule ou de la capacité (Q36).
     */
    public function holdsCapacity(): bool
    {
        return $this === self::Confirmed || $this === self::Pending;
    }

    /**
     * Réservation encore modifiable ou annulable.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Draft, self::Pending, self::Confirmed];
    }
}
