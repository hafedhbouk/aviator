<?php

namespace App\Models;

use App\Enums\VehicleDocumentType;
use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document daté d'un véhicule (B-05) : assurance, visite technique, vignette,
 * carte grise. Une nouvelle échéance = un nouveau document ; l'historique est
 * conservé (aucune suppression). Modifié uniquement par SaveVehicleDocument.
 * Pièce jointe sur le disque privé (lot L3).
 *
 * @property int $id
 * @property int $vehicle_id
 * @property VehicleDocumentType $type
 * @property int|null $supplier_id
 * @property string|null $reference
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property Money|null $amount
 * @property string|null $file_path
 * @property int|null $created_by
 */
#[Fillable(['vehicle_id', 'type', 'supplier_id', 'reference', 'valid_from', 'valid_until', 'amount', 'created_by'])]
class VehicleDocument extends Model
{
    use AuditsChanges;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VehicleDocumentType::class,
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'amount' => MoneyCast::class,
        ];
    }

    /**
     * Le document est-il valide à cette date (bornes incluses) ? Un document sans
     * échéance (carte grise) est valide dès son début.
     */
    public function isValidOn(CarbonImmutable $date): bool
    {
        $day = $date->startOfDay();

        return ($this->valid_from === null || $this->valid_from->lessThanOrEqualTo($day))
            && ($this->valid_until === null || $this->valid_until->greaterThanOrEqualTo($day));
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
