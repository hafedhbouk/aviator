<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Domain\Availability\Exceptions\InvalidPeriod;
use Carbon\CarbonImmutable;

/**
 * Période demandée pour un véhicule, à l'heure de Tunis, à la minute près.
 * Le départ précède strictement le retour.
 */
final readonly class Period
{
    public CarbonImmutable $startsAt;

    public CarbonImmutable $endsAt;

    public function __construct(CarbonImmutable $startsAt, CarbonImmutable $endsAt)
    {
        $timezone = (string) config('app.timezone');
        $this->startsAt = $startsAt->timezone($timezone)->startOfMinute();
        $this->endsAt = $endsAt->timezone($timezone)->startOfMinute();

        if ($this->endsAt->lessThanOrEqualTo($this->startsAt)) {
            throw InvalidPeriod::endBeforeStart();
        }
    }

    public static function between(string $startsAt, string $endsAt): self
    {
        $timezone = (string) config('app.timezone');

        return new self(CarbonImmutable::parse($startsAt, $timezone), CarbonImmutable::parse($endsAt, $timezone));
    }

    /**
     * Fin de l'occupation, délai de préparation compris.
     */
    public function endsWithBuffer(int $bufferMinutes): CarbonImmutable
    {
        return $this->endsAt->addMinutes(max(0, $bufferMinutes));
    }
}
