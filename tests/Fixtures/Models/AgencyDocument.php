<?php

namespace Tests\Fixtures\Models;

use App\Support\Agency\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * Document de test rattaché à une agence (table test_agency_documents).
 *
 * @property int $id
 * @property int $agency_id
 * @property string $title
 */
class AgencyDocument extends Model
{
    use BelongsToAgency;

    protected $table = 'test_agency_documents';

    protected $fillable = ['agency_id', 'title'];
}
