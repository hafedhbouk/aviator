<?php

namespace App\Models;

use App\Domain\Setup\OpeningHours;
use App\Support\Agency\BelongsToAgency;
use App\Support\Audit\AuditsChanges;
use Database\Factories\AgencyLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Lieu de prise en charge ou de restitution d'une agence, avec ses horaires (A-02).
 * Soumis à la portée par agence (AgencyScope). Modifié uniquement par
 * App\Domain\Setup\Actions\SaveAgencyLocation ; jamais supprimé (désactivation).
 *
 * @property int $id
 * @property int $agency_id
 * @property string $name
 * @property string|null $address
 * @property bool $is_pickup
 * @property bool $is_return
 * @property array<string, list<array{open: string, close: string}>>|null $opening_hours
 * @property bool $is_active
 */
#[Fillable(['agency_id', 'name', 'address', 'is_pickup', 'is_return', 'opening_hours', 'is_active'])]
class AgencyLocation extends Model
{
    use AuditsChanges;
    use BelongsToAgency;

    /** @use HasFactory<AgencyLocationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_pickup' => 'boolean',
            'is_return' => 'boolean',
            'opening_hours' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function openingHours(): OpeningHours
    {
        return OpeningHours::fromInput($this->opening_hours);
    }
}
