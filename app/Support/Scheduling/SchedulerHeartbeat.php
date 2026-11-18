<?php

declare(strict_types=1);

namespace App\Support\Scheduling;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Battement de cœur du planificateur : chaque passage enregistre son horodatage
 * (les deux derniers sont conservés). « aviator:check-environment » en déduit si
 * le cron tourne et à quelle cadence (1 minute attendue, 5 minutes = mode dégradé, D20).
 */
class SchedulerHeartbeat
{
    public const CACHE_KEY = 'aviator:scheduler:heartbeats';

    public function beat(): void
    {
        $runs = $this->runs();
        array_unshift($runs, CarbonImmutable::now()->getTimestamp());

        Cache::forever(self::CACHE_KEY, array_slice($runs, 0, 2));
    }

    /**
     * Horodatages Unix des derniers passages, le plus récent en premier.
     *
     * @return list<int>
     */
    public function runs(): array
    {
        $runs = Cache::get(self::CACHE_KEY, []);

        return is_array($runs) ? array_values(array_filter($runs, 'is_int')) : [];
    }

    /**
     * Secondes écoulées depuis le dernier passage, ou null si aucun passage.
     */
    public function secondsSinceLastRun(): ?int
    {
        $last = $this->runs()[0] ?? null;

        return $last === null ? null : CarbonImmutable::now()->getTimestamp() - $last;
    }

    /**
     * Intervalle mesuré entre les deux derniers passages, en secondes.
     */
    public function measuredInterval(): ?int
    {
        $runs = $this->runs();

        return count($runs) < 2 ? null : $runs[0] - $runs[1];
    }
}
