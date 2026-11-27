<?php

declare(strict_types=1);

namespace App\Domain\Billing\Numbering;

use App\Domain\Billing\Exceptions\NumberingException;
use App\Models\FiscalReference;
use App\Models\Sequence;
use App\Models\SequenceSeries;
use App\Models\SequenceSeriesChange;
use App\Support\Sequences\SequenceAllocator;
use App\Support\Sequences\SequencePeriod;
use App\Support\Sequences\Series;
use App\Support\Sequences\SeriesFormat;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Change le format d'une série de numérotation (D27-3), pour les prochains numéros
 * seulement : les numéros attribués ne changent jamais.
 *
 * Refusé :
 * - un format invalide (sans {SEQUENCE}, remise à zéro sans {YEAR}…) ;
 * - pour une série fiscale, le format avec l'année ou la remise à zéro annuelle tant
 *   que le verrou D27-2 est fermé : il s'ouvre avec une version du référentiel
 *   **postérieure** à la v1.0 (confirmation écrite du cabinet, CLAUDE.md règle 3),
 *   enregistrée sur la série ;
 * - un format qui peut reproduire un numéro d'une autre série (format actuel ou
 *   ancien), ou un numéro de la même série produit par un ancien format d'un autre
 *   squelette (un changement de longueur seule ne peut rien reproduire : le compteur
 *   ne fait qu'avancer) ;
 * - une longueur trop courte pour le prochain numéro.
 *
 * Le compteur suit la série : changer le préfixe ne le remet pas à zéro. Quand la
 * remise à zéro annuelle s'active, le compteur de l'année en cours reprend la valeur
 * du compteur continu (la remise à zéro joue au 1er janvier suivant) ; quand elle se
 * retire, le compteur continu reprend celle de l'année en cours.
 */
final class ChangeSeriesFormat
{
    /** Version du référentiel dont la §4.1 fixe la numérotation continue sans l'année. */
    public const BASELINE_REFERENCE_VERSION = '1.0';

    public function __construct(private readonly SequenceAllocator $sequences) {}

    public function handle(
        Series $series,
        ?string $prefix,
        ?string $template,
        ?int $padding,
        ?SequencePeriod $resetPeriod,
        string $decidedBy,
        ?string $confirmationVersion = null,
        ?string $operator = null,
        ?string $host = null,
        ?CarbonInterface $at = null,
    ): ?SequenceSeriesChange {
        $decidedBy = trim($decidedBy);

        if ($decidedBy === '') {
            throw NumberingException::decisionRequired();
        }

        $date = $at ?? CarbonImmutable::now();
        $config = SequenceSeries::ensure($series);
        $current = $config->format();

        try {
            $next = new SeriesFormat(
                $prefix ?? $current->prefix,
                $template ?? $current->template,
                $padding ?? $current->padding,
                $resetPeriod ?? $current->resetPeriod,
            );
        } catch (InvalidArgumentException $exception) {
            throw NumberingException::invalidFormat($exception->getMessage());
        }

        if ($next == $current) {
            return null;
        }

        $confirmation = $confirmationVersion === null || trim($confirmationVersion) === ''
            ? null
            : $this->confirmation(trim($confirmationVersion));
        $unlocks = $this->checkLocks($series, $config, $next, $confirmation);
        $this->checkCollisions($series, $next);

        $currentKey = $current->counterKey($series, $date);
        $nextKey = $next->counterKey($series, $date);
        $this->sequences->ensureCounterExists($currentKey);
        $this->sequences->ensureCounterExists($nextKey);

        return DB::transaction(function () use ($series, $current, $next, $currentKey, $nextKey, $unlocks, $confirmation, $decidedBy, $operator, $host, $date): SequenceSeriesChange {
            /** @var SequenceSeries $config */
            $config = SequenceSeries::query()->where('code', $series->value)->lockForUpdate()->firstOrFail();

            if ($config->format() != $current) {
                throw NumberingException::changedMeanwhile();
            }

            $counter = Sequence::query()->where('key', $currentKey)->lockForUpdate()->firstOrFail();
            $value = $counter->last_value;

            if ($nextKey !== $currentKey) {
                $target = Sequence::query()->where('key', $nextKey)->lockForUpdate()->firstOrFail();
                $target->last_value = max($target->last_value, $value);
                $target->save();
                $value = $target->last_value;
            }

            if ($value + 1 > $next->maxValue()) {
                throw NumberingException::tooShort($value + 1, $next->padding);
            }

            $config->fill([
                'prefix' => $next->prefix,
                'template' => $next->template,
                'padding' => $next->padding,
                'reset_period' => $next->resetPeriod,
                ...$unlocks,
            ])->save();

            $change = SequenceSeriesChange::query()->create([
                'sequence_series_id' => $config->id,
                'before' => $current->toArray($date),
                'after' => $next->toArray($date),
                'decided_by' => $decidedBy,
                'confirmation_reference_id' => $unlocks === [] ? null : $confirmation?->id,
                'operator' => $operator,
                'host' => $host,
            ]);

            activity('audit')
                ->event('numbering_format_changed')
                ->performedOn($config)
                ->withProperties([
                    'series' => $series->value,
                    'before' => $current->toArray($date),
                    'after' => $next->toArray($date),
                    'decided_by' => $decidedBy,
                    'confirmation' => $unlocks === [] ? null : $confirmation?->version,
                    'operator' => $operator,
                    'host' => $host,
                    'via' => 'console',
                ])
                ->log("Format de numérotation changé : {$series->label()}, {$current->template} → {$next->template}");

            return $change;
        });
    }

    /**
     * Version du référentiel qui confirme le changement : enregistrée, et postérieure
     * à la version de base.
     */
    private function confirmation(string $version): FiscalReference
    {
        $reference = FiscalReference::query()->where('version', $version)->first()
            ?? throw NumberingException::unknownConfirmation($version);
        $baseline = FiscalReference::query()->where('version', self::BASELINE_REFERENCE_VERSION)->first()
            ?? throw NumberingException::baselineMissing();

        if ($reference->is($baseline) || $reference->validated_at->lessThanOrEqualTo($baseline->validated_at)) {
            throw NumberingException::confirmationNotLater($version);
        }

        return $reference;
    }

    /**
     * Verrous D27-2 des séries fiscales ; renvoie les colonnes de verrou à ouvrir.
     *
     * @return array<string, int>
     */
    private function checkLocks(Series $series, SequenceSeries $config, SeriesFormat $next, ?FiscalReference $confirmation): array
    {
        if (! $series->isFiscal()) {
            return [];
        }

        $unlocks = [];

        if ($next->hasYear() && $config->year_format_reference_id === null) {
            $unlocks['year_format_reference_id'] = $confirmation === null
                ? throw NumberingException::yearFormatLocked($series)
                : $confirmation->id;
        }

        if ($next->resetsYearly() && $config->yearly_reset_reference_id === null) {
            $unlocks['yearly_reset_reference_id'] = $confirmation === null
                ? throw NumberingException::yearlyResetLocked($series)
                : $confirmation->id;
        }

        return $unlocks;
    }

    private function checkCollisions(Series $series, SeriesFormat $next): void
    {
        foreach (Series::cases() as $other) {
            foreach ($this->history($other) as $format) {
                if ($other !== $series && $next->overlaps($format)) {
                    throw NumberingException::collidesWith($other, $format->skeleton());
                }

                if ($other === $series && $format->skeleton() !== $next->skeleton() && $next->overlaps($format)) {
                    throw NumberingException::collidesWithOwnHistory($format->skeleton());
                }
            }
        }
    }

    /**
     * Format actuel et anciens formats d'une série (journal).
     *
     * @return list<SeriesFormat>
     */
    private function history(Series $series): array
    {
        $config = SequenceSeries::ensure($series);
        $formats = [$config->format()];

        foreach ($config->changes()->get() as $change) {
            $before = $change->before;
            $formats[] = new SeriesFormat($before['prefix'], $before['template'], $before['padding'], SequencePeriod::from($before['reset_period']));
        }

        return $formats;
    }
}
