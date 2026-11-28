<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use LogicException;

/**
 * Modification refusée d'un document de facturation verrouillé (RG-06, D56). Le
 * message s'affiche tel quel.
 */
final class InvoiceLockedException extends LogicException
{
    public static function locked(string $label): self
    {
        return new self("{$label} validée ne se modifie plus : une correction passe par un avoir.");
    }

    public static function lineLocked(): self
    {
        return new self('Les lignes et les taxes d\'une facture validée ne se modifient plus : une correction passe par un avoir.');
    }

    public static function bulkUpdate(): self
    {
        return new self('Modification en masse refusée : elle toucherait une facture validée.');
    }

    public static function transition(string $from, string $to): self
    {
        return new self("Changement de statut refusé : « {$from} » ne peut pas devenir « {$to} ».");
    }

    public static function setOnce(string $column): self
    {
        return new self("La valeur de « {$column} » est déjà enregistrée et ne se modifie plus.");
    }

    public static function createdAsDraft(): self
    {
        return new self('Une facture se crée en brouillon, sans numéro : le numéro est attribué à la validation (RG-07).');
    }

    public static function incompleteValidation(): self
    {
        return new self('Validation incomplète : numéro, série, année, date de validation, client et émetteur figés sont obligatoires.');
    }

    public static function creditNoteWithoutOrigin(): self
    {
        return new self('Un avoir est toujours lié à sa facture d\'origine (E-04).');
    }

    public static function cancellationReasonRequired(): self
    {
        return new self('Le motif d\'annulation est obligatoire.');
    }

    public static function cannotDelete(): self
    {
        return new self('Une facture validée ne se supprime jamais.');
    }
}
