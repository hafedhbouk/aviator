<?php

namespace App\Models;

use App\Enums\DamageDocumentKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Pièce d'un dossier de dommage (N2), sur le disque privé ; servie par
 * DamageFileDocumentController après la policy du contrat. Ajout seul.
 *
 * @property int $id
 * @property int $damage_file_id
 * @property DamageDocumentKind $kind
 * @property string $path
 * @property string $sha256
 * @property int $size_bytes
 * @property int|null $uploaded_by
 */
class DamageFileDocument extends Model
{
    protected $fillable = ['damage_file_id', 'kind', 'path', 'sha256', 'size_bytes', 'uploaded_by'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Une pièce de dossier de dommage ne se modifie pas.');
        });

        static::deleting(function (): never {
            throw new LogicException('Une pièce de dossier de dommage ne se supprime pas.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => DamageDocumentKind::class, 'size_bytes' => 'integer'];
    }

    /**
     * @return BelongsTo<DamageFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(DamageFile::class, 'damage_file_id');
    }
}
