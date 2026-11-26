<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Trace d'une alerte envoyée (B-07, B-13) : empêche de la renvoyer pour la même échéance.
 *
 * @property int $id
 * @property string $event
 * @property string $subject_type
 * @property int $subject_id
 * @property CarbonImmutable $due_on
 * @property int $recipients_count
 * @property CarbonImmutable $sent_at
 */
class SentAlert extends Model
{
    public $timestamps = false;

    protected $fillable = ['event', 'subject_type', 'subject_id', 'due_on', 'recipients_count', 'sent_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => 'immutable_date',
            'recipients_count' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
