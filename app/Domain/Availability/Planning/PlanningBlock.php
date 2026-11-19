<?php

declare(strict_types=1);

namespace App\Domain\Availability\Planning;

use Carbon\CarbonImmutable;

/**
 * Occupation d'un véhicule telle que lue en base, avant tout filtrage selon le lecteur.
 * Ne quitte jamais le serveur : c'est PlanningProjection qui décide de ce qui est affiché.
 */
final readonly class PlanningBlock
{
    public function __construct(
        public int $vehicleId,
        public int $agencyId,
        public PlanningBlockType $type,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?CarbonImmutable $bufferEndsAt = null,
        public ?string $reference = null,
        public ?string $customerName = null,
        public bool $late = false,
        public ?string $url = null,
    ) {}
}
