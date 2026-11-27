<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Photo d'un état des lieux (D-06, D44), ré-encodée en JPEG sans métadonnées et rangée
 * sur le disque privé ; servie par InspectionFileController après la policy du contrat.
 *
 * @property int $id
 * @property int $inspection_id
 * @property string $path
 * @property int $size_bytes
 * @property string $sha256
 * @property int $width
 * @property int $height
 * @property int|null $created_by
 */
class InspectionPhoto extends Model
{
    protected $fillable = ['inspection_id', 'path', 'size_bytes', 'sha256', 'width', 'height', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Inspection, $this>
     */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }
}
