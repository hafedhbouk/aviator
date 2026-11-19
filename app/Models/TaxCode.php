<?php

namespace App\Models;

use App\Enums\TaxNature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Code fiscal (référentiel v1.0, §2) : liste fermée de 15 codes créée par le seeder,
 * sans création ni modification dans l'interface. La nature et le taux viennent du
 * code et de ses règles, jamais du libellé (§2.2 : « ne jamais appliquer
 * automatiquement l'exonération à toute ligne comportant le mot assurance »).
 *
 * @property int $id
 * @property string $code
 * @property string $label
 * @property TaxNature $nature
 * @property bool $requires_justification
 * @property string $legal_reference
 * @property string|null $description
 */
#[Fillable(['code', 'label', 'nature', 'requires_justification', 'legal_reference', 'description'])]
class TaxCode extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un code fiscal ne se modifie pas : il vient du référentiel validé.'));
        static::deleting(fn () => throw new LogicException('Un code fiscal ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nature' => TaxNature::class,
            'requires_justification' => 'boolean',
        ];
    }

    /**
     * @return HasMany<TaxRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(TaxRule::class)->orderBy('valid_from');
    }
}
