<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Session stockée en base (SESSION_DRIVER=database). Modèle technique, utilisé
 * seulement pour couper les sessions d'un compte (SessionRevoker).
 *
 * @property string $id
 * @property int|null $user_id
 */
class DatabaseSession extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    public function getTable(): string
    {
        return (string) config('session.table', 'sessions');
    }
}
