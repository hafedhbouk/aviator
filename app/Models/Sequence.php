<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Compteur de numérotation (une ligne par préfixe et par période). Ne s'utilise
 * que par SequenceAllocator, sous verrou de ligne.
 *
 * @property int $id
 * @property string $key
 * @property int $last_value
 */
#[Fillable(['key', 'last_value'])]
class Sequence extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_value' => 'integer',
        ];
    }
}
