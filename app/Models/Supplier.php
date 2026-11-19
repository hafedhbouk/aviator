<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fournisseur (G-05) : garage, assureur, société de leasing, banque, autre. Fiche
 * simple (nom, matricule fiscal, contact). Modifié par
 * App\Domain\Setup\Actions\SaveSupplier ; jamais supprimé (désactivation).
 *
 * @property int $id
 * @property string $type
 * @property string $name
 * @property string|null $tax_id
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property bool $is_active
 */
#[Fillable(['type', 'name', 'tax_id', 'phone', 'email', 'address', 'is_active'])]
class Supplier extends Model
{
    use AuditsChanges;

    public const TYPES = [
        'garage' => 'Garage',
        'insurer' => 'Assureur',
        'leasing_company' => 'Société de leasing',
        'bank' => 'Banque',
        'other' => 'Autre',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
