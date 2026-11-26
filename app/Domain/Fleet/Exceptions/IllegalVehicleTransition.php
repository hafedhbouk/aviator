<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Exceptions;

use App\Enums\VehicleStatus;
use RuntimeException;

/**
 * Transition de statut absente de la machine à états (architecture §7).
 */
final class IllegalVehicleTransition extends RuntimeException
{
    public static function between(VehicleStatus $from, VehicleStatus $to): self
    {
        return new self("Passage de « {$from->label()} » à « {$to->label()} » impossible pour ce véhicule.");
    }
}
