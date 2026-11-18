<?php

declare(strict_types=1);

namespace App\Support\Environment;

enum CheckStatus: string
{
    case Ok = 'OK';
    case Warning = 'AVERTISSEMENT';
    case Error = 'ERREUR';
}
