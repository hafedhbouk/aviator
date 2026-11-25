<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Financing\Actions;

use App\Domain\Fleet\Actions\RetireVehicle;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Enums\FinancingOutcome;
use App\Enums\FinancingType;
use App\Enums\RetirementReason;
use App\Models\User;
use App\Models\VehicleFinancing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fin d'un financement (B-14, P2) :
 * - leasing, option levée : le véhicule reste au parc et devient propriété d'Aviator ;
 * - leasing, restitution : le véhicule sort du parc (RetireVehicle, motif « restitution
 *   en fin de leasing ») ;
 * - crédit : remboursé.
 * Une seule fois ; date jamais dans le futur ; journalisé.
 */
final class SettleFinancing
{
    public function __construct(private readonly RetireVehicle $retire) {}

    public function handle(VehicleFinancing $financing, FinancingOutcome $outcome, CarbonImmutable $on, User $actor): VehicleFinancing
    {
        if (! $actor->can('update', $financing)) {
            throw FleetException::notAllowed('les financements');
        }

        if (! $financing->isOpen()) {
            throw FleetException::financingClosed();
        }

        $allowed = $financing->type === FinancingType::Leasing
            ? [FinancingOutcome::OptionExercised, FinancingOutcome::Returned]
            : [FinancingOutcome::Repaid];

        if (! in_array($outcome, $allowed, true)) {
            throw FleetException::invalidOutcome($outcome->label(), $financing->type->label());
        }

        if ($on->startOfDay()->isAfter(CarbonImmutable::today())) {
            throw FleetException::invalidPaymentDate();
        }

        return DB::transaction(function () use ($financing, $outcome, $on, $actor): VehicleFinancing {
            $financing->fill(['outcome' => $outcome, 'outcome_on' => $on->startOfDay()]);
            $financing->save();

            if ($outcome === FinancingOutcome::Returned) {
                $this->retire->handle($financing->vehicle, [
                    'retired_on' => $on->toDateString(),
                    'retirement_reason' => RetirementReason::LeaseReturn->value,
                ], $actor);
            }

            return $financing;
        });
    }
}
