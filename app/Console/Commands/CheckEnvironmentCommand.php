<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Environment\CheckResult;
use App\Support\Environment\CheckStatus;
use App\Support\Environment\EnvironmentChecker;
use Illuminate\Console\Command;

/**
 * Contrôle de l'environnement, en lecture seule, à lancer sur le poste, la recette
 * et la production (Q8, Q71). Code de retour non nul en cas d'erreur.
 */
class CheckEnvironmentCommand extends Command
{
    /** @var string */
    protected $signature = 'aviator:check-environment';

    /** @var string */
    protected $description = 'Contrôle l\'environnement (PHP, MariaDB, cron, clés, HTTPS, droits, e-mail) sans rien modifier';

    public function handle(EnvironmentChecker $checker): int
    {
        $results = $checker->run();

        $this->table(
            ['Statut', 'Contrôle', 'Constat', 'Action conseillée'],
            array_map(fn (CheckResult $r): array => [$r->status->value, $r->check, $r->finding, $r->advice], $results),
        );

        $count = fn (CheckStatus $status): int => count(array_filter($results, fn (CheckResult $r): bool => $r->status === $status));
        $errors = $count(CheckStatus::Error);
        $summary = sprintf('%d OK, %d avertissement(s), %d erreur(s).', $count(CheckStatus::Ok), $count(CheckStatus::Warning), $errors);

        if ($errors > 0) {
            $this->components->error($summary.' Corrigez les erreurs avant de mettre l\'application en service.');

            return self::FAILURE;
        }

        $this->components->info($summary);

        return self::SUCCESS;
    }
}
