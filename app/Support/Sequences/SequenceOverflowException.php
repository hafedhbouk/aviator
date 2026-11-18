<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use RuntimeException;

/**
 * La séquence a atteint le plus grand numéro de sa longueur : on n'allonge jamais
 * le format en silence.
 */
final class SequenceOverflowException extends RuntimeException
{
    public static function for(string $key, int $length): self
    {
        return new self("La séquence « {$key} » a atteint son maximum ({$length} chiffres) : allongez le format après validation.");
    }
}
