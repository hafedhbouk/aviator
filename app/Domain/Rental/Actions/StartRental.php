<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Approvals\ApprovalGate;
use App\Domain\Availability\BlockRegistry;
use App\Domain\Availability\Period;
use App\Domain\Crm\CustomerRiskGate;
use App\Domain\Fleet\Actions\RecordMileage;
use App\Domain\Fleet\VehicleStatusService;
use App\Domain\Rental\ContractDrivers;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Setup\CategoryRules;
use App\Enums\ContractStatus;
use App\Enums\InspectionType;
use App\Enums\VehicleStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Inspection;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Validation du départ (parcours P3, étape 7 ; D-06, B-05, §5.1). Dans une seule
 * transaction, sous verrou du contrat puis du véhicule :
 *
 * 1. contrat en brouillon, de l'agence de l'acteur (RG-13) ;
 * 2. état des lieux de départ complet : kilométrage, carburant, signature du client ;
 * 3. remise approuvée si elle dépasse le seuil, dérogation approuvée pour un client sur
 *    liste noire (D46) ;
 * 4. conducteur principal présent, chaque conducteur contrôlé à la date réelle
 *    (ContractDrivers) ;
 * 5. assurance et visite technique valides au départ (scénario 10, VehicleStatusService) ;
 * 6. relevé du kilométrage (jamais inférieur au dernier relevé), véhicule « Loué »,
 *    contrat « En cours », niveau de carburant à rendre, état des lieux validé, donc
 *    figé.
 *
 * Caution (F-01) : ajoutée à ces conditions en phase 6.
 */
final class StartRental
{
    public function __construct(
        private readonly ApprovalGate $approvals,
        private readonly CustomerRiskGate $risk,
        private readonly ContractDrivers $drivers,
        private readonly RecordMileage $mileage,
        private readonly VehicleStatusService $statuses,
        private readonly BlockRegistry $blocks,
    ) {}

    public function handle(Contract $contract, User $actor, ?CarbonImmutable $departedAt = null): Contract
    {
        if (! $actor->can('update', $contract)) {
            throw ContractException::notAllowed('ce contrat');
        }

        $departedAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($contract, $actor, $departedAt): Contract {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);

            if ($locked->status !== ContractStatus::Draft) {
                throw ContractException::invalidStatus($locked->status->label(), 'départ');
            }

            $inspection = Inspection::query()->where('contract_id', $locked->id)->where('type', InspectionType::Departure->value)->first();
            $this->assertInspectionComplete($inspection);
            /** @var Inspection $inspection */
            $this->approvals->assertDiscountCleared($locked->discount_amount, $locked->discount_approval_id);
            $this->assertOverride($locked);
            $this->drivers->assertReadyForDeparture($locked, $departedAt);

            // Départ en avance : l'occupation est avancée sous le verrou du véhicule (refusée
            // si l'avance chevauche une autre occupation, délai de préparation compris).
            if ($departedAt->lessThan($locked->starts_at)) {
                $buffer = CategoryRules::for(VehicleCategory::query()->findOrFail($locked->vehicle_category_id))->preparationBufferMinutes;
                $this->blocks->occupy($locked->vehicle_id, new Period($departedAt, $locked->ends_at), $locked, $locked->agency_id, $buffer);
            }

            $vehicle = Vehicle::query()->withoutGlobalScopes()->findOrFail($locked->vehicle_id);
            $this->mileage->handle($vehicle, (int) $inspection->mileage, $actor, $inspection, $departedAt, "Départ du contrat {$locked->number}");
            $this->statuses->transition($vehicle->refresh(), VehicleStatus::Rented, cause: $locked, actor: $actor,
                reason: "Départ du contrat {$locked->number}", departureAt: $departedAt);

            $locked->fill([
                'status' => ContractStatus::Active,
                'departed_at' => $departedAt,
                'fuel_eighths_required' => $inspection->fuel_eighths,
            ])->save();

            $inspection->fill(['validated_at' => $departedAt, 'validated_by' => $actor->id])->save();

            return $locked;
        });
    }

    private function assertInspectionComplete(?Inspection $inspection): void
    {
        $missing = match (true) {
            $inspection === null => 'état des lieux de départ non commencé',
            $inspection->mileage === null => 'kilométrage',
            $inspection->fuel_eighths === null => 'niveau de carburant',
            $inspection->signature_path === null => 'signature du client',
            default => null,
        };

        if ($missing !== null) {
            throw ContractException::inspectionIncomplete($missing);
        }
    }

    /**
     * Client sur liste noire : dérogation approuvée pour ce contrat, ou pour la
     * réservation dont il est issu.
     */
    private function assertOverride(Contract $contract): void
    {
        $customer = Customer::query()->withoutGlobalScopes()->findOrFail($contract->customer_id);

        if (! $this->risk->isBlacklisted($customer)) {
            return;
        }

        $reservation = $contract->reservation_id === null ? null : Reservation::query()->withoutGlobalScopes()->find($contract->reservation_id);

        $approved = $this->approvals->overrideApproved($contract, $contract->override_approval_id)
            || ($reservation !== null && $this->approvals->overrideApproved($reservation, $reservation->override_approval_id));

        if (! $approved) {
            throw ContractException::overrideNotApproved();
        }
    }
}
