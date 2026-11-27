<?php

declare(strict_types=1);

namespace App\Domain\Rental;

use App\Domain\Availability\Period;
use App\Domain\Pricing\BillableDurationCalculator;
use App\Enums\ChargeNature;
use App\Models\Contract;
use App\Models\Inspection;
use App\Settings\PricingSettings;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Calcul des frais du retour (D-09, RG-09, scénario 4), sans rien écrire : l'écran de
 * retour l'affiche avant la validation, RegisterReturn le fige. Montants HT au millime,
 * jamais de float ; chaque ligne garde le détail du calcul et les paramètres utilisés.
 *
 * - **Retard** (Q14) : unité « jour » : jours facturés du départ prévu au retour réel,
 *   moins jours facturés du départ prévu au retour prévu, avec le même décompte que le
 *   devis (tranches de 24 h, tolérance sur la dernière, Q32) ; au tarif journalier figé
 *   du contrat (D48). Unité « heure » (D51, Q91) : heures entamées au-delà de la
 *   tolérance, au tarif horaire figé ; sans tarif, aucun frais.
 * - **Kilomètres** (Q43) : forfait par jour × jours facturés (prévus ou réels, le plus
 *   grand) ; kilomètres au-delà au prix figé ; illimité ou sans prix : aucun frais.
 * - **Carburant** (D50, Q44) : huitièmes manquants × capacité ÷ 8 × prix du litre
 *   (litres arrondis au millilitre), plus les frais de service ; sans capacité ni prix :
 *   aucun frais.
 * - **Dommages** (Q45) : nouveaux dommages du retour, montant saisi par l'agent ; le
 *   total est plafonné par la franchise figée du contrat (vide = sans plafond).
 */
final class ReturnCharges
{
    public function __construct(
        private readonly BillableDurationCalculator $duration,
        private readonly PricingSettings $pricing,
    ) {}

    /**
     * @param  array<int, Money>  $damageAmounts  identifiant du dommage nouveau => montant HT
     * @return list<array{nature: ChargeNature, label: string, quantity: string, unit_amount: Money, amount: Money, calculation: array<string, mixed>, inspection_damage_id: int|null}>
     */
    public function compute(Contract $contract, Inspection $departure, Inspection $return, CarbonImmutable $returnedAt, array $damageAmounts = []): array
    {
        return array_values(array_filter([
            $this->late($contract, $returnedAt),
            $this->mileage($contract, $departure, $return, $returnedAt),
            $this->fuel($contract, $departure, $return),
            ...$this->damages($contract, $return, $damageAmounts),
        ]));
    }

    /**
     * @return array{nature: ChargeNature, label: string, quantity: string, unit_amount: Money, amount: Money, calculation: array<string, mixed>, inspection_damage_id: null}|null
     */
    private function late(Contract $contract, CarbonImmutable $returnedAt): ?array
    {
        $lateMinutes = $returnedAt->greaterThan($contract->ends_at) ? (int) $contract->ends_at->diffInMinutes($returnedAt) : 0;
        $tolerance = max(0, $this->pricing->late_tolerance_minutes);
        $base = ['planned_return' => $contract->ends_at->toIso8601String(), 'actual_return' => $returnedAt->toIso8601String(),
            'late_minutes' => $lateMinutes, 'tolerance_minutes' => $tolerance, 'unit' => $this->pricing->late_unit];

        if ($lateMinutes <= $tolerance) {
            return null;
        }

        if ($this->pricing->late_unit === 'hour') {
            if ($contract->late_hour_amount === null) {
                return null;
            }

            $hours = intdiv($lateMinutes + 59, 60);

            return $this->line(ChargeNature::LateReturn, "Retard de {$hours} heure(s)", (string) $hours, $contract->late_hour_amount,
                $contract->late_hour_amount->multipliedBy($hours, RoundingMode::Unnecessary), $base + ['hours' => $hours]);
        }

        $planned = count($this->duration->days(new Period($contract->starts_at, $contract->ends_at)));
        $actual = count($this->duration->days(new Period($contract->starts_at, $returnedAt)));
        $days = max(0, $actual - $planned);

        if ($days === 0) {
            return null;
        }

        return $this->line(ChargeNature::LateReturn, "Retard : {$days} journée(s) au tarif du contrat", (string) $days, $contract->daily_rate_amount,
            $contract->daily_rate_amount->multipliedBy($days, RoundingMode::Unnecessary),
            $base + ['planned_days' => $planned, 'actual_days' => $actual, 'day_mode' => $this->pricing->day_mode]);
    }

    /**
     * @return array{nature: ChargeNature, label: string, quantity: string, unit_amount: Money, amount: Money, calculation: array<string, mixed>, inspection_damage_id: null}|null
     */
    private function mileage(Contract $contract, Inspection $departure, Inspection $return, CarbonImmutable $returnedAt): ?array
    {
        if ($contract->hasUnlimitedMileage() || $contract->included_km_per_day === null || $contract->extra_km_amount === null) {
            return null;
        }

        $end = $returnedAt->greaterThan($contract->ends_at) ? $returnedAt : $contract->ends_at;
        $days = count($this->duration->days(new Period($contract->starts_at, $end)));
        $allowance = $contract->included_km_per_day * $days;
        $driven = (int) $return->mileage - (int) $departure->mileage;
        $extra = $driven - $allowance;

        if ($extra <= 0) {
            return null;
        }

        return $this->line(ChargeNature::ExtraKm, "{$extra} km au-delà du forfait", (string) $extra, $contract->extra_km_amount,
            $contract->extra_km_amount->multipliedBy($extra, RoundingMode::Unnecessary),
            ['departure_mileage' => $departure->mileage, 'return_mileage' => $return->mileage, 'driven_km' => $driven,
                'included_km_per_day' => $contract->included_km_per_day, 'billed_days' => $days, 'allowance_km' => $allowance]);
    }

    /**
     * @return array{nature: ChargeNature, label: string, quantity: string, unit_amount: Money, amount: Money, calculation: array<string, mixed>, inspection_damage_id: null}|null
     */
    private function fuel(Contract $contract, Inspection $departure, Inspection $return): ?array
    {
        $required = $contract->fuel_eighths_required ?? $departure->fuel_eighths;
        $missing = (int) $required - (int) $return->fuel_eighths;

        if ($required === null || $missing <= 0 || $contract->fuel_tank_liters === null || $contract->fuel_liter_amount === null) {
            return null;
        }

        $liters = BigDecimal::of($missing)->multipliedBy($contract->fuel_tank_liters)->dividedBy(8, 3, RoundingMode::HalfUp);
        $fuel = $contract->fuel_liter_amount->multipliedBy($liters, RoundingMode::HalfUp);
        $service = $contract->fuel_service_amount ?? Money::zero();

        return $this->line(ChargeNature::Fuel, "Carburant manquant : {$missing}/8 de réservoir ({$liters} L)", (string) $liters, $contract->fuel_liter_amount,
            $fuel->plus($service),
            ['required_eighths' => $required, 'returned_eighths' => $return->fuel_eighths, 'missing_eighths' => $missing,
                'tank_liters' => $contract->fuel_tank_liters, 'liters' => (string) $liters, 'fuel_amount' => $fuel->toDecimalString(),
                'service_amount' => $service->toDecimalString()]);
    }

    /**
     * @param  array<int, Money>  $amounts
     * @return list<array{nature: ChargeNature, label: string, quantity: string, unit_amount: Money, amount: Money, calculation: array<string, mixed>, inspection_damage_id: int}>
     */
    private function damages(Contract $contract, Inspection $return, array $amounts): array
    {
        $cap = $contract->insurance_excess_amount;
        $remaining = $cap;
        $lines = [];

        foreach ($return->damages()->where('is_new', true)->get() as $damage) {
            $requested = $amounts[$damage->id] ?? null;

            if ($requested === null || ! $requested->isPositive()) {
                continue;
            }

            $amount = $remaining !== null && $requested->compareTo($remaining) > 0 ? $remaining : $requested;

            if ($remaining !== null) {
                $remaining = $remaining->minus($amount);
            }

            $lines[] = [
                'nature' => ChargeNature::Damage,
                'label' => "Dommage : {$damage->zone->label()}, {$damage->description}",
                'quantity' => '1',
                'unit_amount' => $amount,
                'amount' => $amount,
                'calculation' => ['requested_amount' => $requested->toDecimalString(), 'excess_cap' => $cap?->toDecimalString(),
                    'capped' => ! $amount->equals($requested), 'severity' => $damage->severity->value],
                'inspection_damage_id' => $damage->id,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $calculation
     * @return array{nature: ChargeNature, label: string, quantity: string, unit_amount: Money, amount: Money, calculation: array<string, mixed>, inspection_damage_id: null}
     */
    private function line(ChargeNature $nature, string $label, string $quantity, Money $unit, Money $amount, array $calculation): array
    {
        return ['nature' => $nature, 'label' => $label, 'quantity' => $quantity, 'unit_amount' => $unit, 'amount' => $amount,
            'calculation' => $calculation, 'inspection_damage_id' => null];
    }
}
