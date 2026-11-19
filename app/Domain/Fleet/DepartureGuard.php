<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Fleet\Exceptions\VehicleNotRentable;
use App\Enums\VehicleDocumentType;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Documents obligatoires au départ en location (B-05, scénario 10) : une assurance
 * et une visite technique valides **à la date de départ** (bornes incluses, jour de
 * Tunis). Utilisé par VehicleStatusService (passage à « Loué »), par l'affectation
 * d'un véhicule à une réservation (lot L11) et par le départ du contrat (phase 5).
 */
final class DepartureGuard
{
    /**
     * Documents obligatoires manquants ou expirés à cette date.
     *
     * @return list<VehicleDocumentType>
     */
    public function missingOn(Vehicle $vehicle, CarbonImmutable $date): array
    {
        $documents = $this->requiredDocuments($vehicle);
        $missing = [];

        foreach (self::required() as $type) {
            $valid = $documents
                ->filter(fn (VehicleDocument $document): bool => $document->type === $type)
                ->contains(fn (VehicleDocument $document): bool => $document->isValidOn($date));

            if (! $valid) {
                $missing[] = $type;
            }
        }

        return $missing;
    }

    /**
     * @throws VehicleNotRentable si un document obligatoire n'est pas valide au départ
     */
    public function assertCanDepart(Vehicle $vehicle, CarbonImmutable $departureAt): void
    {
        $date = $departureAt->timezone(config('app.timezone'));
        $missing = $this->missingOn($vehicle, $date);

        if ($missing !== []) {
            throw VehicleNotRentable::missingDocuments(
                $vehicle->label(),
                array_map(fn (VehicleDocumentType $type): string => mb_strtolower($type->label()), $missing),
                $date->format('d/m/Y'),
            );
        }
    }

    /**
     * Documents valides au départ mais qui expirent pendant la location prévue :
     * alerte, pas refus (architecture §7).
     *
     * @return list<VehicleDocumentType>
     */
    public function expiringDuring(Vehicle $vehicle, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $start = $startsAt->timezone(config('app.timezone'));
        $end = $endsAt->timezone(config('app.timezone'));
        $documents = $this->requiredDocuments($vehicle);
        $expiring = [];

        foreach (self::required() as $type) {
            $coversEnd = $documents
                ->filter(fn (VehicleDocument $document): bool => $document->type === $type)
                ->contains(fn (VehicleDocument $document): bool => $document->isValidOn($end));

            if (! $coversEnd && ! in_array($type, $this->missingOn($vehicle, $start), true)) {
                $expiring[] = $type;
            }
        }

        return $expiring;
    }

    /**
     * @return list<VehicleDocumentType>
     */
    public static function required(): array
    {
        return array_values(array_filter(
            VehicleDocumentType::cases(),
            fn (VehicleDocumentType $type): bool => $type->requiredForDeparture(),
        ));
    }

    /**
     * @return Collection<int, VehicleDocument>
     */
    private function requiredDocuments(Vehicle $vehicle): Collection
    {
        return VehicleDocument::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('type', array_map(fn (VehicleDocumentType $type): string => $type->value, self::required()))
            ->get();
    }
}
