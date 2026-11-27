<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Availability\BlockRegistry;
use App\Domain\Availability\VehicleLock;
use App\Domain\Fleet\Actions\RecordMileage;
use App\Domain\Fleet\VehicleStatusService;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Rental\ReturnCharges;
use App\Enums\ChargeNature;
use App\Enums\ContractStatus;
use App\Enums\InspectionType;
use App\Enums\VehicleStatus;
use App\Models\Contract;
use App\Models\ContractCharge;
use App\Models\Inspection;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Validation du retour (parcours P4 ; D-06, D-08, D-09, RG-04, RG-09, §5.1, D47, Q73).
 * Dans une seule transaction, sous verrou du contrat :
 *
 * 1. contrat en cours, de l'agence de l'acteur (RG-13) ;
 * 2. état des lieux de retour complet : kilométrage (jamais inférieur au départ, RG-04),
 *    carburant, signature du client ou absence motivée (boîte à clés, Q73) ;
 * 3. heure de retour : maintenant, ou heure déclarée (retour hors horaires), jamais
 *    avant le départ ni dans le futur ;
 * 4. frais calculés (ReturnCharges) et **figés** dans `contract_charges` avec leur
 *    calcul ; restitution anticipée sans remboursement (D-08, Q14) ;
 * 5. kilométrage relevé ; véhicule « Disponible », ou « En maintenance » si l'agent le
 *    signale à réparer (D47) ; occupation libérée ; contrat « Restitué » ; état des
 *    lieux validé, donc figé.
 *
 * Facture et caution : phase 6 (la clôture du contrat les exigera).
 */
final class RegisterReturn
{
    public function __construct(
        private readonly ReturnCharges $charges,
        private readonly RecordMileage $mileage,
        private readonly VehicleStatusService $statuses,
        private readonly BlockRegistry $blocks,
    ) {}

    /**
     * @param  array<string, mixed>  $data  returned_at (heure déclarée, facultative), vehicle_to_repair (bool),
     *                                      damage_amounts (identifiant du dommage => montant HT)
     */
    public function handle(Contract $contract, array $data, User $actor): Contract
    {
        if (! $actor->can('update', $contract)) {
            throw ContractException::notAllowed('ce contrat');
        }

        $damageAmounts = $this->damageAmounts($data['damage_amounts'] ?? []);

        return DB::transaction(function () use ($contract, $data, $damageAmounts, $actor): Contract {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);
            // Ordre des verrous de la disponibilité (D38) : catégorie, puis véhicule ; sinon
            // interblocage avec une réservation simultanée (MariaDB 1213).
            app(VehicleLock::class)->category($locked->vehicle_category_id);

            if ($locked->status !== ContractStatus::Active) {
                throw ContractException::invalidStatus($locked->status->label(), 'retour');
            }

            $departure = Inspection::query()->where('contract_id', $locked->id)->where('type', InspectionType::Departure->value)->firstOrFail();
            $return = $this->completeReturn(
                Inspection::query()->where('contract_id', $locked->id)->where('type', InspectionType::Return->value)->first(),
                $departure,
            );
            $returnedAt = $this->returnedAt($data['returned_at'] ?? null, $locked);

            foreach ($this->preview($locked, $departure, $return, $returnedAt, $damageAmounts) as $line) {
                ContractCharge::query()->create([
                    'contract_id' => $locked->id,
                    'nature' => $line['nature'],
                    'label' => $line['label'],
                    'quantity' => $line['quantity'],
                    'unit_amount' => $line['unit_amount'],
                    'amount' => $line['amount'],
                    'calculation' => $line['calculation'],
                    'inspection_damage_id' => $line['inspection_damage_id'],
                    'created_by' => $actor->id,
                ]);
            }

            $vehicle = Vehicle::query()->withoutGlobalScopes()->findOrFail($locked->vehicle_id);
            $this->mileage->handle($vehicle, (int) $return->mileage, $actor, $return, CarbonImmutable::now(), "Retour du contrat {$locked->number}");

            $toRepair = (bool) ($data['vehicle_to_repair'] ?? false);
            $this->statuses->transition($vehicle->refresh(), $toRepair ? VehicleStatus::Maintenance : VehicleStatus::Available,
                cause: $locked, actor: $actor, reason: $toRepair ? "Retour du contrat {$locked->number} : véhicule à réparer" : "Retour du contrat {$locked->number}");
            $this->blocks->release($locked);

            $locked->fill(['status' => ContractStatus::Returned, 'returned_at' => $returnedAt])->save();
            $return->fill([
                'returned_at_declared' => $return->customer_absent_reason !== null ? $returnedAt : null,
                'validated_at' => CarbonImmutable::now(),
                'validated_by' => $actor->id,
            ])->save();

            return $locked;
        });
    }

    /**
     * Frais du retour, sans rien écrire (affichés avant la validation).
     *
     * @param  array<int, Money>  $damageAmounts
     * @return list<array{nature: ChargeNature, label: string, quantity: string, unit_amount: Money, amount: Money, calculation: array<string, mixed>, inspection_damage_id: int|null}>
     */
    public function preview(Contract $contract, Inspection $departure, Inspection $return, CarbonImmutable $returnedAt, array $damageAmounts = []): array
    {
        return $this->charges->compute($contract, $departure, $return, $returnedAt, $damageAmounts);
    }

    /**
     * État des lieux de retour complet (RG-04 compris), sinon refus motivé.
     */
    private function completeReturn(?Inspection $return, Inspection $departure): Inspection
    {
        if ($return === null) {
            throw ContractException::inspectionIncomplete('état des lieux de retour non commencé');
        }

        $missing = match (true) {
            $return->mileage === null => 'kilométrage',
            $return->fuel_eighths === null => 'niveau de carburant',
            $return->signature_path === null && $return->customer_absent_reason === null => 'signature du client ou motif de son absence',
            default => null,
        };

        if ($missing !== null) {
            throw ContractException::inspectionIncomplete($missing);
        }

        if ((int) $return->mileage < (int) $departure->mileage) {
            throw ContractException::mileageBelow((int) $departure->mileage, 'kilométrage de départ (RG-04)');
        }

        return $return;
    }

    private function returnedAt(mixed $declared, Contract $contract): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if ($declared === null || $declared === '') {
            return $now;
        }

        if (! is_string($declared) || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', trim($declared)) !== 1) {
            throw ValidationException::withMessages(['returned_at' => 'Heure de retour invalide.']);
        }

        $at = CarbonImmutable::parse(trim($declared), (string) config('app.timezone'));
        $departedAt = $contract->departed_at ?? $contract->starts_at;

        if ($at->greaterThan($now) || $at->lessThan($departedAt)) {
            throw ContractException::invalidReturnTime();
        }

        return $at;
    }

    /**
     * @return array<int, Money>
     */
    private function damageAmounts(mixed $input): array
    {
        $amounts = [];

        foreach (is_array($input) ? $input : [] as $damageId => $value) {
            try {
                $amount = AmountInput::parse($value);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(["damage_amounts.{$damageId}" => "Montant du dommage : {$exception->getMessage()}"]);
            }

            if ($amount !== null && is_numeric($damageId)) {
                $amounts[(int) $damageId] = $amount;
            }
        }

        return $amounts;
    }
}
