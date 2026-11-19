<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Mode de paiement (A-07, E-06) : espèces, chèque, carte, virement, traite. Liste
 * paramétrable ; référence exigée ou non à l'encaissement (numéro de chèque…).
 * Modifié par App\Domain\Setup\Actions\SavePaymentMethod ; jamais supprimé.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $requires_reference
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'requires_reference', 'is_active'])]
class PaymentMethod extends Model
{
    use AuditsChanges;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_reference' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
