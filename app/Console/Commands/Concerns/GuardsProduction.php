<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Commandes sensibles : refus en production sans l'option --force (D17, D23).
 */
trait GuardsProduction
{
    protected function refusesInProduction(): bool
    {
        if (app()->environment('production') && ! (bool) $this->option('force')) {
            $this->components->error('Environnement de production : relancez avec --force après avoir vérifié la sauvegarde.');

            return true;
        }

        return false;
    }
}
