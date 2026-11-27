<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Availability\Period;
use App\Domain\Pricing\QuoteCalculator;
use App\Domain\Rental\Exceptions\ContractException;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Retire la remise d'un contrat en brouillon après le refus du super admin (D46) :
 * devis recalculé sans remise par le même service qu'au comptoir, comme pour une
 * réservation. Un contrat parti n'est jamais modifié ainsi (il n'a pu partir qu'avec
 * une remise approuvée).
 */
final class RemoveContractDiscount
{
    public function __construct(private readonly QuoteCalculator $quotes) {}

    public function handle(Contract $contract, User $actor): Contract
    {
        if (! $actor->can('update', $contract)) {
            throw ContractException::notAllowed('ce contrat');
        }

        return DB::transaction(function () use ($contract): Contract {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);

            if ($locked->status !== ContractStatus::Draft) {
                throw ContractException::invalidStatus($locked->status->label(), 'retrait de la remise');
            }

            if ($locked->discount_amount->isZero()) {
                return $locked;
            }

            $quote = $this->quotes->quote(
                $locked->vehicle_category_id,
                $locked->agency_id,
                new Period($locked->starts_at, $locked->ends_at),
                $locked->options()->pluck('quantity', 'option_id')->map(fn ($quantity): int => (int) $quantity)->all(),
            );
            $days = $quote->days;
            $lastDay = $days === [] ? null : end($days);

            $locked->fill([
                'quote' => $quote->toArray(),
                'rental_amount' => $quote->rentalAmount,
                'options_amount' => $quote->optionsAmount,
                'discount_amount' => Money::zero(),
                'total_amount' => $quote->totalExcludingTax,
                'daily_rate_amount' => $lastDay['daily_amount'] ?? $locked->daily_rate_amount,
                'discount_approval_id' => null,
            ]);
            $locked->save();

            foreach ($quote->options as $line) {
                $locked->options()->where('option_id', $line['option_id'])->update([
                    'unit_amount' => $line['unit_amount']->toDecimalString(),
                    'total_amount' => $line['amount']->toDecimalString(),
                ]);
            }

            return $locked;
        });
    }
}
