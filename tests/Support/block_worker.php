<?php

/*
 * Processus PHP distinct, lancé par VehicleBlockConcurrencyTest : il démarre sa propre
 * application Laravel (sa propre connexion MariaDB à aviator_test) et tente d'occuper
 * un véhicule. Il affiche le résultat en JSON sur sa dernière ligne.
 *
 * Usage : php block_worker.php <véhicule> <agence> <départ> <retour> <délai> <mode> [<attente ms>]
 *   mode « new »         : nouvelle source (document de test) ;
 *   mode « move:<id> »   : déplace l'occupation de la source <id> ;
 *   mode « stale »       : nouvelle source, mais la transaction lit d'abord (instantané
 *                          de REPEATABLE READ), affiche « READY », attend, puis occupe.
 */

use App\Domain\Availability\BlockRegistry;
use App\Domain\Availability\Exceptions\VehicleUnavailable;
use App\Domain\Availability\Period;
use App\Models\VehicleBlock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Models\AgencyDocument;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $vehicleId, $agencyId, $startsAt, $endsAt, $buffer, $mode] = $argv;
$waitMs = (int) ($argv[7] ?? 0);

$registry = $app->make(BlockRegistry::class);
$period = Period::between($startsAt, $endsAt);

$occupy = function (AgencyDocument $source) use ($registry, $vehicleId, $agencyId, $period, $buffer): VehicleBlock {
    return $registry->occupy((int) $vehicleId, $period, $source, (int) $agencyId, (int) $buffer);
};

try {
    if (str_starts_with($mode, 'move:')) {
        $block = $occupy(AgencyDocument::query()->findOrFail((int) substr($mode, 5)));
    } elseif ($mode === 'stale') {
        $source = AgencyDocument::query()->create(['agency_id' => (int) $agencyId, 'title' => 'Instantané ancien']);
        $block = DB::transaction(function () use ($occupy, $source, $waitMs): VehicleBlock {
            VehicleBlock::query()->count(); // première lecture : fixe l'instantané
            fwrite(STDOUT, "READY\n");
            fflush(STDOUT);
            usleep($waitMs * 1000);

            return $occupy($source);
        });
    } else {
        $block = $occupy(AgencyDocument::query()->create(['agency_id' => (int) $agencyId, 'title' => 'Saisie']));
    }

    echo json_encode(['ok' => true, 'block' => $block->id, 'finished_at' => microtime(true)], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (VehicleUnavailable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage(), 'finished_at' => microtime(true)], JSON_THROW_ON_ERROR), PHP_EOL;
}
