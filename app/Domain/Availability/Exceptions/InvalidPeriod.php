<?php

declare(strict_types=1);

namespace App\Domain\Availability\Exceptions;

use RuntimeException;

final class InvalidPeriod extends RuntimeException
{
    public static function endBeforeStart(): self
    {
        return new self('Période invalide : le retour doit suivre le départ.');
    }
}
