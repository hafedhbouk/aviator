<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Availability\Period;
use App\Settings\PricingSettings;
use Carbon\CarbonImmutable;

/**
 * Durée facturée d'une location (C-06, RG-09, Q32), selon `pricing.day_mode` :
 *
 * - `rolling_24h` (défaut, Q32) : tranches de 24 h depuis l'heure de départ ; la
 *   dernière tranche commencée n'est comptée que si elle dépasse la tolérance de
 *   retard (`pricing.late_tolerance_minutes`, Q14 : 60 min) ; une journée au moins.
 *   Chaque jour facturé commence à l'heure de départ (J, J + 24 h…).
 * - `calendar` : nombre de dates entre le jour de départ et le jour de retour, une
 *   journée au moins (le jour du retour n'est pas compté) ; chaque jour facturé est
 *   une date calendaire.
 *
 * Le jour qui commence chaque tranche donne sa saison (Q33).
 */
final class BillableDurationCalculator
{
    public function __construct(private readonly PricingSettings $settings) {}

    /**
     * @return list<CarbonImmutable> début de chaque jour facturé
     */
    public function days(Period $period): array
    {
        $count = $this->settings->day_mode === 'calendar'
            ? max(1, (int) $period->startsAt->startOfDay()->diffInDays($period->endsAt->startOfDay()))
            : $this->rollingDays($period);

        $origin = $this->settings->day_mode === 'calendar' ? $period->startsAt->startOfDay() : $period->startsAt;

        $days = [];
        for ($i = 0; $i < $count; $i++) {
            $days[] = $origin->addDays($i);
        }

        return $days;
    }

    private function rollingDays(Period $period): int
    {
        $minutes = (int) $period->startsAt->diffInMinutes($period->endsAt);
        $full = intdiv($minutes, 1440);
        $remainder = $minutes % 1440;

        if ($remainder > max(0, $this->settings->late_tolerance_minutes)) {
            $full++;
        }

        return max(1, $full);
    }
}
