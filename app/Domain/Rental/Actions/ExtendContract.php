<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Approvals\Actions\RequestApproval;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Availability\BlockRegistry;
use App\Domain\Availability\Period;
use App\Domain\Availability\VehicleLock;
use App\Domain\Pricing\BillableDurationCalculator;
use App\Domain\Pricing\QuoteCalculator;
use App\Domain\Rental\Exceptions\ContractException;
use App\Domain\Setup\CategoryRules;
use App\Enums\ApprovalType;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\ContractExtension;
use App\Models\ContractOption;
use App\Models\Option;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Prolongation d'un contrat en cours (D-07, scénario 3, D48, Q93, Q94).
 *
 * - Nouvelle date de retour postérieure à l'actuelle ; contrôle de disponibilité sur la
 *   nouvelle période, délai de préparation compris, **sous le verrou du véhicule** :
 *   l'occupation du contrat est déplacée par BlockRegistry, qui refuse tout chevauchement
 *   avec une réservation ou un autre contrat (VehicleUnavailable). Deux saisies
 *   simultanées sont servies l'une après l'autre.
 * - Prix : jours facturés ajoutés (même décompte que le devis, Q32) au tarif journalier
 *   figé du contrat (D48), sans remise automatique ; options par jour prolongées au prix
 *   figé, plafond de l'option respecté (Q94, valeur par défaut).
 * - Remise sur la prolongation : au-delà du seuil, demande d'approbation motivée (Q93).
 * - Trace en ajout seul (`contract_extensions`) ; montants du contrat mis à jour.
 */
final class ExtendContract
{
    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly BillableDurationCalculator $duration,
        private readonly QuoteCalculator $quotes,
        private readonly RequestApproval $requestApproval,
    ) {}

    /**
     * @param  array<string, mixed>  $data  ends_at (AAAA-MM-JJ HH:MM, heure de Tunis), discount_amount, discount_reason
     */
    public function handle(Contract $contract, array $data, User $actor): ContractExtension
    {
        if (! $actor->can('update', $contract)) {
            throw ContractException::notAllowed('ce contrat');
        }

        $newEndsAt = $this->endsAt($data['ends_at'] ?? null);

        try {
            $discount = AmountInput::parse($data['discount_amount'] ?? null) ?? Money::zero();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['discount_amount' => "Le champ remise : {$exception->getMessage()}"]);
        }

        $reason = is_scalar($data['discount_reason'] ?? null) ? trim((string) $data['discount_reason']) : '';

        return DB::transaction(function () use ($contract, $newEndsAt, $discount, $reason, $actor): ContractExtension {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);
            // Ordre des verrous de la disponibilité (D38) : catégorie, puis véhicule ; sinon
            // interblocage avec une réservation simultanée (MariaDB 1213).
            app(VehicleLock::class)->category($locked->vehicle_category_id);

            if ($locked->status !== ContractStatus::Active) {
                throw ContractException::invalidStatus($locked->status->label(), 'prolongation');
            }

            if (! $newEndsAt->greaterThan($locked->ends_at)) {
                throw ContractException::extensionNotLater($locked->ends_at->timezone((string) config('app.timezone'))->format('d/m/Y H:i'));
            }

            $start = $locked->departed_at !== null && $locked->departed_at->lessThan($locked->starts_at) ? $locked->departed_at : $locked->starts_at;
            $buffer = CategoryRules::for(VehicleCategory::query()->findOrFail($locked->vehicle_category_id))->preparationBufferMinutes;

            // Contrôle de disponibilité et déplacement de l'occupation, sous verrou du véhicule.
            $this->blocks->occupy($locked->vehicle_id, new Period($start, $newEndsAt), $locked, $locked->agency_id, $buffer);

            $oldDays = count($this->duration->days(new Period($locked->starts_at, $locked->ends_at)));
            $newDays = count($this->duration->days(new Period($locked->starts_at, $newEndsAt)));
            $addedDays = max(0, $newDays - $oldDays);
            $addedRental = $locked->daily_rate_amount->multipliedBy($addedDays, RoundingMode::Unnecessary);
            [$optionLines, $addedOptions] = $this->options($locked, $oldDays, $newDays);
            $subtotal = $addedRental->plus($addedOptions);

            if ($discount->isNegative() || $discount->compareTo($subtotal) > 0) {
                throw ContractException::extensionDiscountTooHigh();
            }

            $needsApproval = $this->quotes->discountNeedsApproval($discount, $subtotal);

            if ($needsApproval && mb_strlen($reason) < RequestApproval::MIN_REASON_LENGTH) {
                throw ApprovalException::reasonRequired('une remise au-delà du seuil de '.$this->quotes->thresholdLabel().' %', RequestApproval::MIN_REASON_LENGTH);
            }

            $extension = ContractExtension::query()->create([
                'contract_id' => $locked->id,
                'previous_ends_at' => $locked->ends_at,
                'new_ends_at' => $newEndsAt,
                'added_days' => $addedDays,
                'daily_rate_amount' => $locked->daily_rate_amount,
                'added_rental_amount' => $addedRental,
                'added_options_amount' => $addedOptions,
                'discount_amount' => $discount,
                'total_amount' => $subtotal->minus($discount),
                'options' => $optionLines,
                'created_by' => $actor->id,
            ]);

            if ($needsApproval) {
                $extension->forceFill(['discount_approval_id' => $this->requestApproval->handle(ApprovalType::Discount, $extension, $locked->agency_id, [
                    'document_number' => $locked->number,
                    'starts_at' => $extension->previous_ends_at->toIso8601String(),
                    'ends_at' => $newEndsAt->toIso8601String(),
                    'subtotal_amount' => $subtotal->toDecimalString(),
                    'discount_amount' => $discount->toDecimalString(),
                    'discount_percent' => (string) $discount->toBigDecimal()->multipliedBy(100)->dividedBy($subtotal->toBigDecimal(), 2, RoundingMode::HalfUp),
                    'threshold_percent' => $this->quotes->thresholdLabel(),
                    'total_excluding_tax' => $subtotal->minus($discount)->toDecimalString(),
                ], $reason, $actor)->id])->save();
            }

            $locked->fill([
                'ends_at' => $newEndsAt,
                'rental_amount' => $locked->rental_amount->plus($addedRental),
                'options_amount' => $locked->options_amount->plus($addedOptions),
                'discount_amount' => $locked->discount_amount->plus($discount),
                'total_amount' => $locked->total_amount->plus($subtotal)->minus($discount),
            ])->save();

            return $extension;
        });
    }

    private function endsAt(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', trim($value)) !== 1) {
            throw ValidationException::withMessages(['ends_at' => 'Nouvelle date de retour invalide.']);
        }

        return CarbonImmutable::parse(trim($value), (string) config('app.timezone'));
    }

    /**
     * Options par jour prolongées au prix figé du contrat, plafond de l'option respecté
     * (Q94). Les options par location ne changent pas.
     *
     * @return array{0: list<array{option_id: int, label: string, quantity: int, unit_amount: string, amount: string}>, 1: Money}
     */
    private function options(Contract $contract, int $oldDays, int $newDays): array
    {
        $lines = [];
        $total = Money::zero();

        foreach (ContractOption::query()->where('contract_id', $contract->id)->get() as $line) {
            $option = Option::query()->find($line->option_id);

            if ($option === null || $option->pricing_unit !== Option::PER_DAY) {
                continue;
            }

            $cap = $option->max_amount_per_rental;
            $before = $line->unit_amount->multipliedBy($oldDays, RoundingMode::Unnecessary);
            $after = $line->unit_amount->multipliedBy($newDays, RoundingMode::Unnecessary);

            if ($cap !== null) {
                $before = $before->compareTo($cap) > 0 ? $cap : $before;
                $after = $after->compareTo($cap) > 0 ? $cap : $after;
            }

            $amount = $after->minus($before)->multipliedBy($line->quantity, RoundingMode::Unnecessary);

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = [
                'option_id' => $option->id,
                'label' => $option->name,
                'quantity' => $line->quantity,
                'unit_amount' => $line->unit_amount->toDecimalString(),
                'amount' => $amount->toDecimalString(),
            ];
            $total = $total->plus($amount);
        }

        return [$lines, $total];
    }
}
