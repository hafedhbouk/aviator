<?php

namespace App\Models;

use App\Support\Sequences\SequenceAllocator;
use App\Support\Sequences\SequencePeriod;
use App\Support\Sequences\Series;
use App\Support\Sequences\SeriesFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Réglage d'une série de numérotation (D27) : format et verrous. Ne se modifie que
 * par App\Domain\Billing\Numbering\ChangeSeriesFormat (commande aviator:numbering),
 * qui journalise chaque changement ; ne se supprime jamais.
 *
 * @property int $id
 * @property Series $code
 * @property string $prefix
 * @property string $template
 * @property int $padding
 * @property SequencePeriod $reset_period
 * @property int|null $year_format_reference_id
 * @property int|null $yearly_reset_reference_id
 * @property-read FiscalReference|null $yearFormatReference
 * @property-read FiscalReference|null $yearlyResetReference
 */
#[Fillable(['code', 'prefix', 'template', 'padding', 'reset_period', 'year_format_reference_id', 'yearly_reset_reference_id'])]
class SequenceSeries extends Model
{
    protected $table = 'sequence_series';

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Une série de numérotation ne se supprime jamais.'));
    }

    /**
     * Ligne de la série, créée au premier usage avec le réglage initial.
     *
     * - Les trois séries sont créées ensemble : aucune insertion ne vient plus tard
     *   dans une table dont une ligne peut être verrouillée (le verrou de MariaDB
     *   couvre aussi l'intervalle qui précède la ligne, voir SequenceAllocator::prepare).
     * - La création passe par la connexion en validation automatique, comme les
     *   compteurs, et la ligne est relue par elle : une lecture dans la transaction du
     *   document ne verrait pas une ligne validée après son début (lecture cohérente).
     */
    public static function ensure(Series $series): self
    {
        $existing = self::query()->where('code', $series->value)->first();

        if ($existing !== null) {
            return $existing;
        }

        $now = now();
        self::on(SequenceAllocator::AUTOCOMMIT_CONNECTION)->insertOrIgnore(array_map(function (Series $case) use ($now): array {
            $format = $case->defaultFormat();

            return [
                'code' => $case->value,
                'prefix' => $format->prefix,
                'template' => $format->template,
                'padding' => $format->padding,
                'reset_period' => $format->resetPeriod->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, Series::cases()));

        $created = self::on(SequenceAllocator::AUTOCOMMIT_CONNECTION)->where('code', $series->value)->firstOrFail();

        return $created->setConnection((new self)->getConnectionName());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => Series::class,
            'padding' => 'integer',
            'reset_period' => SequencePeriod::class,
        ];
    }

    public function format(): SeriesFormat
    {
        return new SeriesFormat($this->prefix, $this->template, $this->padding, $this->reset_period);
    }

    /**
     * @return BelongsTo<FiscalReference, $this>
     */
    public function yearFormatReference(): BelongsTo
    {
        return $this->belongsTo(FiscalReference::class, 'year_format_reference_id');
    }

    /**
     * @return BelongsTo<FiscalReference, $this>
     */
    public function yearlyResetReference(): BelongsTo
    {
        return $this->belongsTo(FiscalReference::class, 'yearly_reset_reference_id');
    }

    /**
     * @return HasMany<SequenceSeriesChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(SequenceSeriesChange::class);
    }
}
