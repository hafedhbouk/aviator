<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Exceptions;

use RuntimeException;

/**
 * Refus métier lié à une approbation, message affichable, sans donnée client.
 */
final class ApprovalException extends RuntimeException
{
    public static function notAllowed(): self
    {
        return new self('Seul le super admin approuve ou refuse une demande.');
    }

    public static function alreadyDecided(string $status): self
    {
        return new self("Cette demande est déjà traitée (« {$status} »).");
    }

    public static function reasonRequired(string $what, int $min): self
    {
        return new self("Motif obligatoire pour {$what} ({$min} caractères au moins).");
    }

    public static function commentRequired(int $min): self
    {
        return new self("Un refus doit être motivé ({$min} caractères au moins).");
    }

    public static function discountPending(): self
    {
        return new self('La remise attend l\'approbation du super admin : départ impossible tant qu\'elle n\'est pas approuvée.');
    }
}
