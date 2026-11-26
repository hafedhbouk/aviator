<?php

declare(strict_types=1);

namespace App\Domain\Rental\Exceptions;

use RuntimeException;

/**
 * Refus métier sur un contrat, message affichable à l'agent, sans donnée client.
 */
final class ContractException extends RuntimeException
{
    public static function notAllowed(string $what): self
    {
        return new self("Action non autorisée sur {$what}.");
    }

    public static function reservationNotConfirmed(string $status): self
    {
        return new self("Seule une réservation confirmée devient un contrat (réservation « {$status} »).");
    }

    public static function vehicleRequired(): self
    {
        return new self('Choisissez le véhicule du contrat.');
    }

    public static function invalidStatus(string $status, string $action): self
    {
        return new self("Contrat « {$status} » : {$action} impossible.");
    }

    public static function reasonRequired(int $min): self
    {
        return new self("Motif obligatoire ({$min} caractères au moins).");
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function driverRefused(string $driver, array $reasons): self
    {
        return new self("Conducteur {$driver} refusé : ".implode(' ; ', $reasons).'.');
    }

    public static function driverBlocked(): self
    {
        return new self('Conducteur refusé pour ce contrat. Contactez le super admin.');
    }

    public static function driverNotOfCustomer(): self
    {
        return new self('Ce conducteur n\'appartient pas au client du contrat.');
    }

    public static function primaryDriverRequired(): self
    {
        return new self('Un conducteur principal est obligatoire avant le départ.');
    }

    public static function startInPast(): self
    {
        return new self('Le départ d\'un contrat ne peut pas être dans le passé.');
    }
}
