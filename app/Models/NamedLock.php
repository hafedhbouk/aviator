<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ligne d'un verrou nommé (D38). Ne s'utilise que par App\Support\Locking\DatabaseLock,
 * sous verrou de ligne ; aucune donnée métier.
 *
 * @property string $name
 */
class NamedLock extends Model
{
    public const UPDATED_AT = null;

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['name'];
}
