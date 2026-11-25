<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsProduction;
use App\Models\Customer;
use App\Models\Driver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Recalcule toutes les empreintes de recherche avec la clé AVIATOR_BLIND_INDEX_KEY
 * actuelle (D8, D23). À lancer, application en maintenance, juste après un
 * changement de clé : sinon les recherches exactes et les contrôles de liste
 * noire ne retrouvent plus les clients.
 */
class RehashBlindIndexCommand extends Command
{
    use GuardsProduction;

    /** @var string */
    protected $signature = 'aviator:rehash-blind-index {--force : Exécuter en production}';

    /** @var string */
    protected $description = 'Recalcule les empreintes de recherche (pièces d\'identité) avec la clé d\'index actuelle';

    public function handle(): int
    {
        if ($this->refusesInProduction()) {
            return self::FAILURE;
        }

        $count = 0;

        Customer::query()->chunkById(200, function (Collection $customers) use (&$count): void {
            /** @var Collection<int, Customer> $customers */
            foreach ($customers as $customer) {
                $customer->refreshSearchKeys();
                $customer->timestamps = false;
                $customer->saveQuietly();
                $count++;
            }
        });

        $drivers = 0;

        Driver::query()->chunkById(200, function (Collection $chunk) use (&$drivers): void {
            /** @var Collection<int, Driver> $chunk */
            foreach ($chunk as $driver) {
                $driver->refreshSearchKeys();
                $driver->timestamps = false;
                $driver->saveQuietly();
                $drivers++;
            }
        });

        activity('security')
            ->event('blind_index_rehashed')
            ->withProperties(['customers' => $count, 'drivers' => $drivers])
            ->log('Empreintes de recherche recalculées');

        $this->components->info("Empreintes recalculées : {$count} client(s), {$drivers} conducteur(s).");

        return self::SUCCESS;
    }
}
