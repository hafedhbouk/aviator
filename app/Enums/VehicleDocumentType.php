<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Document daté d'un véhicule (B-05, B-08). L'assurance et la visite technique
 * conditionnent le départ en location (scénario 10, lot L5).
 */
enum VehicleDocumentType: string
{
    case Insurance = 'insurance';
    case TechnicalInspection = 'technical_inspection';
    case RoadTax = 'road_tax';
    case Registration = 'registration';

    public function label(): string
    {
        return match ($this) {
            self::Insurance => 'Assurance',
            self::TechnicalInspection => 'Visite technique',
            self::RoadTax => 'Vignette',
            self::Registration => 'Carte grise',
        };
    }

    /**
     * Document sans lequel le véhicule ne peut pas partir en location.
     */
    public function requiredForDeparture(): bool
    {
        return $this === self::Insurance || $this === self::TechnicalInspection;
    }

    /**
     * La carte grise n'a pas d'échéance ; les autres documents en ont une.
     */
    public function hasExpiry(): bool
    {
        return $this !== self::Registration;
    }
}
