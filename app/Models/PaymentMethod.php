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
     * Mode système de l'encaissement « retenue sur caution » (Q51, D58) : créé à la
     * première retenue, **inactif** pour ne jamais être proposé à la saisie d'un
     * encaissement ; seule l'application d'une retenue approuvée l'utilise.
     */
    public const DEPOSIT_RETENTION = 'deposit_retention';

    public static function depositRetention(): self
    {
        return self::query()->firstOrCreate(['code' => self::DEPOSIT_RETENTION], [
            'name' => 'Retenue sur caution',
            'requires_reference' => false,
            'is_active' => false,
        ]);
    }

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
