<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Version validée du référentiel fiscal (CLAUDE.md, règle 3) : source, empreinte
 * SHA-256, validation. Une ligne par version ; **jamais modifiée ni supprimée** : une
 * nouvelle version s'ajoute à côté, avec sa propre confirmation. Les documents de
 * docs/ n'étant pas livrés sur le serveur (D25), l'empreinte est conservée ici.
 *
 * @property int $id
 * @property string $version
 * @property string $title
 * @property string $file_name
 * @property string $sha256
 * @property string $validation_source
 * @property CarbonImmutable $validated_at
 * @property string|null $attestation_file_name
 */
#[Fillable(['version', 'title', 'file_name', 'sha256', 'validation_source', 'validated_at', 'attestation_file_name'])]
class FiscalReference extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une référence fiscale validée ne se modifie jamais : ajoutez une nouvelle version.'));
        static::deleting(fn () => throw new LogicException('Une référence fiscale validée ne se supprime jamais.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validated_at' => 'immutable_date',
        ];
    }

    public function label(): string
    {
        return "v{$this->version} ({$this->validated_at->format('d/m/Y')})";
    }
}
