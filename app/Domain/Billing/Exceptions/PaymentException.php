<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * Encaissement ou imputation refusé (L7, E-06, E17, Q81, D37, D57, RG-11). Le message
 * s'affiche tel quel.
 */
final class PaymentException extends RuntimeException
{
    public static function notAllowed(): self
    {
        return new self('Action non autorisée sur cet encaissement ou cette facture.');
    }

    public static function inactiveMethod(string $method): self
    {
        return new self("Le mode « {$method} » est inactif.");
    }

    public static function referenceRequired(string $method): self
    {
        return new self("Le mode « {$method} » exige une référence (numéro de chèque, de virement, d'autorisation).");
    }

    public static function bankRequired(string $method): self
    {
        return new self("Le mode « {$method} » exige le nom de la banque (E17).");
    }

    public static function invalidReservation(string $reason): self
    {
        return new self("Acompte refusé : {$reason}");
    }

    public static function cancelled(): self
    {
        return new self('Cet encaissement est annulé : il ne s\'impute plus.');
    }

    public static function invalidInvoice(string $reason): self
    {
        return new self("Imputation refusée : {$reason}");
    }

    public static function invoiceExceeded(string $number, string $remaining): self
    {
        return new self("Imputation refusée : il reste {$remaining} dû sur la facture {$number}.");
    }

    public static function paymentExceeded(string $remaining): self
    {
        return new self("Imputation refusée : il reste {$remaining} non imputé sur cet encaissement.");
    }

    public static function reasonRequired(int $length): self
    {
        return new self("Un motif d'au moins {$length} caractères est obligatoire pour annuler un encaissement.");
    }

    public static function nothingToRemind(): self
    {
        return new self('Cette facture n\'a plus de reste dû : rien à relancer.');
    }

    public static function alreadyCancelled(): self
    {
        return new self('Cet encaissement est déjà annulé.');
    }
}
