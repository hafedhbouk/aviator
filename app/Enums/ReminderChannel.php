<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canal d'une relance d'impayé (E-08, Q53) : manuel seulement ; ni SMS ni e-mail
 * (hors périmètre), le push au client arrive avec le lot 2.
 */
enum ReminderChannel: string
{
    case Phone = 'phone';
    case Letter = 'letter';
    case Visit = 'visit';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Appel',
            self::Letter => 'Courrier',
            self::Visit => 'Visite',
        };
    }
}
