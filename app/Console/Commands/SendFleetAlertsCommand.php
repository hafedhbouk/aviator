<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Fleet\Alerts\DueDateAlerts;
use Illuminate\Console\Command;

/**
 * Exécution manuelle des alertes avant échéance (planifiées à 06:05, D15).
 */
class SendFleetAlertsCommand extends Command
{
    protected $signature = 'fleet:alerts';

    protected $description = 'Envoie les alertes d\'assurance, de visite technique et de fin de leasing (une fois par échéance)';

    public function handle(DueDateAlerts $alerts): int
    {
        $this->info(sprintf('%d alerte(s) envoyée(s).', $alerts->run()));

        return self::SUCCESS;
    }
}
