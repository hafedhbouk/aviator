<?php

declare(strict_types=1);

namespace App\Domain\Availability\Planning;

use Carbon\CarbonImmutable;

/**
 * Bloc du planning tel qu'il peut être envoyé au navigateur d'un lecteur donné.
 * Pour un bloc d'une agence sur laquelle le lecteur n'agit pas : libellé « Occupé »,
 * ni référence, ni nom, ni lien, identifiant opaque (Q38).
 */
final readonly class PlanningEntry
{
    public function __construct(
        public string $id,
        public int $vehicleId,
        public PlanningBlockType $type,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?CarbonImmutable $bufferEndsAt,
        public string $label,
        public bool $detailed,
        public ?string $url,
        public bool $late,
    ) {}
}
