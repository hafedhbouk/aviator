<?php

declare(strict_types=1);

namespace App\Support\Documents;

use DomainException;

final class NumberedDocumentException extends DomainException
{
    public static function cannotDelete(string $number): self
    {
        return new self("Le document {$number} a reçu un numéro : il ne peut pas être supprimé. Annulez-le par un statut ou un document d'annulation (avoir).");
    }

    public static function cannotDeleteInBulk(): self
    {
        return new self('Suppression refusée : la sélection contient des documents numérotés. Annulez-les par un statut ou un document d\'annulation (avoir).');
    }
}
