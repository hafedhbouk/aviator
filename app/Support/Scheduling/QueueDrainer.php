<?php

declare(strict_types=1);

namespace App\Support\Scheduling;

use Illuminate\Support\Facades\Artisan;

/**
 * Traite la file « database » depuis le planificateur, dans son propre processus
 * (D15) : aucun sous-processus PHP, donc aucune dépendance à proc_open (D14).
 * Le travail s'arrête quand la file est vide ou après 50 s, pour laisser la place
 * au passage suivant du cron (chaque minute, ou toutes les 5 minutes en mode
 * dégradé, D20). Le verrou anti-chevauchement est posé par la planification.
 */
class QueueDrainer
{
    /**
     * @return array<string, bool|int|string>
     */
    public function options(): array
    {
        return [
            '--stop-when-empty' => true,
            '--max-time' => 50,
            '--tries' => 3,
            '--queue' => 'default',
        ];
    }

    public function drain(): int
    {
        return Artisan::call('queue:work', $this->options());
    }
}
