<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Objet d'une demande d'approbation au super admin (D46, Q52). Phase 5 : remise au-delà
 * du seuil (RG-10) et dérogation à la liste noire (RG-15). Les retenues sur caution
 * (F-05) arrivent en phase 6 et la mise sur liste noire (K-11) en phase 7.
 */
enum ApprovalType: string
{
    case Discount = 'discount';
    case BlacklistOverride = 'blacklist_override';

    public function label(): string
    {
        return match ($this) {
            self::Discount => 'Remise au-delà du seuil',
            self::BlacklistOverride => 'Dérogation à la liste noire',
        };
    }
}
