<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fiche société d'Aviator (A-01) : une seule ligne, d'identifiant SINGLETON_ID,
 * créée au premier enregistrement de la fiche. Modifiée uniquement par
 * App\Domain\Setup\Actions\SaveCompanyProfile.
 *
 * @property int $id
 * @property string $legal_name
 * @property string|null $trade_name
 * @property string|null $tax_id
 * @property string|null $trade_register
 * @property string|null $address
 * @property string|null $city
 * @property string|null $phone
 * @property string|null $email
 * @property string $currency
 * @property string|null $logo_path
 * @property string|null $invoice_legal_mentions
 */
#[Fillable([
    'legal_name', 'trade_name', 'tax_id', 'trade_register', 'address', 'city', 'phone', 'email',
    'currency', 'logo_path', 'invoice_legal_mentions',
])]
class Company extends Model
{
    use AuditsChanges;

    public const SINGLETON_ID = 1;

    /** Seule devise de l'application (D26). */
    public const CURRENCY = 'TND';

    protected $table = 'company';

    /**
     * La fiche société, ou null tant qu'elle n'a jamais été enregistrée.
     */
    public static function current(): ?self
    {
        return self::query()->find(self::SINGLETON_ID);
    }
}
