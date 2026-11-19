<?php

declare(strict_types=1);

namespace App\Domain\Availability\Planning;

use App\Models\User;

/**
 * Ce qu'un utilisateur peut voir du planning (C-02, RG-13, RG-14, Q38).
 *
 * Les blocs des agences sur lesquelles il agit (toutes pour le super admin et l'admin)
 * sont détaillés : référence, client, lien. Les autres deviennent « Occupé », sans nom,
 * sans numéro, sans lien et avec un identifiant opaque, pour qu'aucune donnée client
 * n'atteigne le navigateur. Indépendant de la bibliothèque d'affichage (D35).
 */
final class PlanningProjection
{
    public const string OCCUPIED_LABEL = 'Occupé';

    /**
     * @param  iterable<PlanningBlock>  $blocks
     * @return list<PlanningEntry>
     */
    public function forUser(User $user, iterable $blocks): array
    {
        $allAgencies = $user->canActOnAllAgencies();
        $agencyIds = $allAgencies ? [] : $user->authorizedAgencyIds();

        $entries = [];
        $hidden = 0;

        foreach ($blocks as $index => $block) {
            $detailed = $allAgencies || in_array($block->agencyId, $agencyIds, true);

            $entries[] = $detailed
                ? new PlanningEntry(
                    id: 'block-'.$index,
                    vehicleId: $block->vehicleId,
                    type: $block->type,
                    startsAt: $block->startsAt,
                    endsAt: $block->endsAt,
                    bufferEndsAt: $block->bufferEndsAt,
                    label: $this->detailedLabel($block),
                    detailed: true,
                    url: $block->url,
                    late: $block->late,
                )
                : new PlanningEntry(
                    id: 'occupied-'.(++$hidden),
                    vehicleId: $block->vehicleId,
                    type: $block->type,
                    startsAt: $block->startsAt,
                    endsAt: $block->endsAt,
                    bufferEndsAt: $block->bufferEndsAt,
                    label: self::OCCUPIED_LABEL,
                    detailed: false,
                    url: null,
                    late: false,
                );
        }

        return $entries;
    }

    private function detailedLabel(PlanningBlock $block): string
    {
        if (! $block->type->concernsCustomer()) {
            return $block->type->label();
        }

        return trim(implode(' · ', array_filter([$block->reference, $block->customerName])));
    }
}
