<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Réglages des contrats, modifiables par le super admin.
 */
class ContractSettings extends Settings
{
    /** Restitution anticipée (D-08, Q14) : « none » = aucun remboursement, seule valeur tant que la règle n'est pas validée. */
    public const EARLY_RETURN_REFUNDS = ['none' => 'Aucun remboursement (la période prévue reste due)'];

    public string $early_return_refund;

    public static function group(): string
    {
        return 'contracts';
    }
}
