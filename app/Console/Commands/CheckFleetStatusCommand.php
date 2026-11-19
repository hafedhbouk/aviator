<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Fleet\StatusConsistencyCheck;
use Illuminate\Console\Command;

/**
 * Exécution manuelle du contrôle quotidien des statuts (planifié à 06:00 dans
 * routes/console.php, D15).
 */
class CheckFleetStatusCommand extends Command
{
    protected $signature = 'fleet:check-status';

    protected $description = 'Met à jour le statut des véhicules selon leurs documents obligatoires';

    public function handle(StatusConsistencyCheck $check): int
    {
        $report = $check->run();

        $this->info(sprintf(
            '%d véhicule(s) contrôlé(s) : %d rendu(s) indisponible(s), %d remis en disponibilité.',
            $report['checked'],
            $report['unavailable'],
            $report['available'],
        ));

        return self::SUCCESS;
    }
}
