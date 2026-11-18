<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use Carbon\CarbonInterface;

/**
 * Période de remise à zéro d'une séquence.
 */
enum SequencePeriod: string
{
    /** Numérotation continue, jamais remise à zéro. */
    case None = 'none';

    /** Remise à zéro chaque année (exemple : RES-2026-00001). */
    case Year = 'year';

    /** Remise à zéro chaque mois (exemple : XXX-202611-00001). */
    case Month = 'month';

    /**
     * Libellé de la période pour une date, dans le fuseau de l'application (Africa/Tunis).
     */
    public function labelFor(CarbonInterface $date): ?string
    {
        $local = $date->copy()->setTimezone((string) config('app.timezone'));

        return match ($this) {
            self::None => null,
            self::Year => $local->format('Y'),
            self::Month => $local->format('Ym'),
        };
    }
}
