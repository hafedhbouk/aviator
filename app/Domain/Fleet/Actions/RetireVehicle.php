<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\VehicleStatusService;
use App\Enums\RetirementReason;
use App\Enums\VehicleStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Money\AmountInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Sortie du parc (B-10) : vente, mise au rebut ou restitution en fin de leasing, avec
 * date et prix de cession, pour information. Statut « Sorti du parc », définitif,
 * par VehicleStatusService (depuis « Disponible », « Indisponible » ou « En
 * maintenance » seulement). Super admin et admin (fleet.manage). Rien n'est supprimé.
 */
final class RetireVehicle
{
    public function __construct(private readonly VehicleStatusService $statuses) {}

    /**
     * @param  array<string, mixed>  $data  retired_on (AAAA-MM-JJ), retirement_reason, disposal_amount
     */
    public function handle(Vehicle $vehicle, array $data, User $actor): Vehicle
    {
        if (! $actor->can('update', $vehicle)) {
            throw FleetException::notAllowed('la sortie du parc');
        }

        if ($vehicle->status === VehicleStatus::Retired) {
            throw FleetException::vehicleRetired($vehicle->label());
        }

        $values = Validator::make([
            'retired_on' => $data['retired_on'] ?? null,
            'retirement_reason' => $data['retirement_reason'] ?? null,
        ], [
            'retired_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'retirement_reason' => ['required', Rule::enum(RetirementReason::class)],
        ], attributes: ['retired_on' => 'date de sortie', 'retirement_reason' => 'motif'])->validate();

        try {
            $amount = AmountInput::parse($data['disposal_amount'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['disposal_amount' => "Le champ prix de cession : {$exception->getMessage()}"]);
        }

        $reason = RetirementReason::from((string) $values['retirement_reason']);

        return DB::transaction(function () use ($vehicle, $values, $reason, $amount, $actor): Vehicle {
            $vehicle = $this->statuses->transition($vehicle, VehicleStatus::Retired, actor: $actor, reason: $reason->label());

            $vehicle->forceFill([
                'retired_on' => $values['retired_on'],
                'retirement_reason' => $reason->value,
                'disposal_amount' => $amount,
            ])->save();

            return $vehicle;
        });
    }
}
