<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsProduction;
use App\Domain\Billing\Exceptions\NumberingException;
use App\Domain\Billing\Numbering\ChangeSeriesFormat;
use App\Models\Sequence;
use App\Models\SequenceSeries;
use App\Support\Sequences\SequencePeriod;
use App\Support\Sequences\Series;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Réglage du format de numérotation d'une série (D27-3), réservé au terminal :
 * l'écran de réglage viendra en phase 9. Sans option de changement, affiche le
 * réglage. Chaque changement est journalisé (avant, après, qui, quand) et ne vaut
 * que pour les prochains numéros. Refusé en production sans --force.
 */
class NumberingCommand extends Command
{
    use GuardsProduction;

    /** @var string */
    protected $signature = 'aviator:numbering
        {series : Série (invoice, credit_note, deposit_receipt)}
        {--prefix= : Nouveau préfixe (exemple : AVI)}
        {--template= : Nouveau modèle, avec les éléments PREFIX, YEAR et SEQUENCE entre accolades}
        {--padding= : Nouvelle longueur du numéro (1 à 12)}
        {--reset= : Remise à zéro : none (jamais) ou year (chaque année)}
        {--confirmation= : Version du référentiel fiscal qui confirme le format avec l\'année ou la remise à zéro}
        {--by= : Personne qui a décidé du changement}
        {--force : Exécuter en production}';

    /** @var string */
    protected $description = 'Affiche ou change le format de numérotation d\'une série (factures, avoirs, reçus de caution), changement journalisé';

    public function handle(ChangeSeriesFormat $change): int
    {
        $series = Series::tryFrom((string) $this->argument('series'));

        if ($series === null) {
            $this->components->error('Série inconnue. Séries : '.implode(', ', array_map(fn (Series $s): string => $s->value, Series::cases())).'.');

            return self::FAILURE;
        }

        $prefix = $this->stringOption('prefix');
        $template = $this->stringOption('template');
        $paddingOption = $this->stringOption('padding');
        $resetOption = $this->stringOption('reset');

        if ($prefix === null && $template === null && $paddingOption === null && $resetOption === null) {
            $this->show($series);

            return self::SUCCESS;
        }

        if ($this->refusesInProduction()) {
            return self::FAILURE;
        }

        if ($paddingOption !== null && preg_match('/^\d{1,2}$/', $paddingOption) !== 1) {
            $this->components->error('Longueur invalide : un nombre entre 1 et 12.');

            return self::FAILURE;
        }

        $reset = $resetOption === null ? null : SequencePeriod::tryFrom($resetOption);

        if ($resetOption !== null && ! in_array($reset, [SequencePeriod::None, SequencePeriod::Year], true)) {
            $this->components->error('Remise à zéro invalide : none ou year.');

            return self::FAILURE;
        }

        $by = trim((string) ($this->option('by') ?: $this->ask('Qui a décidé de ce changement (nom et fonction) ?')));

        try {
            $result = $change->handle(
                $series,
                $prefix,
                $template,
                $paddingOption === null ? null : (int) $paddingOption,
                $reset,
                $by,
                $this->stringOption('confirmation'),
                get_current_user() ?: null,
                gethostname() ?: null,
            );
        } catch (NumberingException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result === null) {
            $this->components->info('Aucun changement : la série a déjà ce format.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Format changé pour la série « %s » : %s → %s (décidé par %s). Les numéros déjà attribués ne changent pas.',
            $series->label(),
            $result->before['example'],
            $result->after['example'],
            $result->decided_by,
        ));
        $this->show($series);

        return self::SUCCESS;
    }

    private function show(Series $series): void
    {
        $config = SequenceSeries::ensure($series);
        $format = $config->format();
        $now = CarbonImmutable::now();
        $last = (int) Sequence::query()->where('key', $format->counterKey($series, $now))->value('last_value');

        $this->components->twoColumnDetail('Série', $series->label().' ('.$series->value.')');
        $this->components->twoColumnDetail('Préfixe', $format->prefix);
        $this->components->twoColumnDetail('Modèle', $format->template);
        $this->components->twoColumnDetail('Longueur', (string) $format->padding);
        $this->components->twoColumnDetail('Remise à zéro', $format->resetsYearly() ? 'chaque année' : 'jamais');
        $this->components->twoColumnDetail('Prochain numéro', $format->format($last + 1, $now));

        if ($series->isFiscal()) {
            $this->components->twoColumnDetail('Format avec l\'année', $config->yearFormatReference === null ? 'verrouillé' : 'ouvert par le référentiel '.$config->yearFormatReference->label());
            $this->components->twoColumnDetail('Remise à zéro annuelle', $config->yearlyResetReference === null ? 'verrouillée' : 'ouverte par le référentiel '.$config->yearlyResetReference->label());
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
