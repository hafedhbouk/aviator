<?php

namespace Tests\Fixtures\Models;

use App\Support\Documents\HasSequenceNumber;
use Illuminate\Database\Eloquent\Model;

/**
 * Document numéroté de test (table test_numbered_documents).
 *
 * @property int $id
 * @property string|null $number
 * @property string $title
 */
class NumberedDocument extends Model
{
    use HasSequenceNumber;

    protected $table = 'test_numbered_documents';

    protected $fillable = ['number', 'title'];
}
