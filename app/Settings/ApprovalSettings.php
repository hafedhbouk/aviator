<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Approbations du super admin (RG-10, F-05, K-11), modifiables par le super admin.
 */
class ApprovalSettings extends Settings
{
    /**
     * Remise accordée sans approbation, en pourcentage (« 5.00 »). Q10, Q52 : 0 %,
     * toute remise passe par l'approbation du super admin.
     */
    public string $discount_threshold_percent;

    /** L'admin du siège peut-il approuver à la place du super admin (Q52 : non) ? */
    public bool $delegate_to_admin;

    public static function group(): string
    {
        return 'approvals';
    }
}
