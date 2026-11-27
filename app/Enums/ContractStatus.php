<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'un contrat de location (D49) : brouillon (créé, état des lieux de départ
 * non validé) → en cours (départ validé) → restitué (retour validé, frais figés) →
 * clôturé (non modifiable, D-11). Annulé seulement depuis le brouillon, motif à l'appui.
 */
enum ContractStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Returned = 'returned';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Active => 'En cours',
            self::Returned => 'Restitué',
            self::Closed => 'Clôturé',
            self::Cancelled => 'Annulé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'info',
            self::Returned => 'warning',
            self::Closed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Contrat qui occupe son véhicule (brouillon, en cours).
     */
    public function holdsVehicle(): bool
    {
        return $this === self::Draft || $this === self::Active;
    }
}
