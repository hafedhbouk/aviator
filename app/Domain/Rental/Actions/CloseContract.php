<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\ContractClosingConditions;
use App\Domain\Rental\Exceptions\ContractException;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Clôture d'un contrat restitué (D49, D-11), sous verrou du contrat : conditions de
 * ContractClosingConditions remplies, puis contrat « Clôturé », non modifiable (le
 * modèle le refuse) ; une correction passe ensuite par un avenant (AmendContract) ou,
 * pour un écart financier, par une facture complémentaire (phase 6).
 */
final class CloseContract
{
    public function __construct(private readonly ContractClosingConditions $conditions) {}

    public function handle(Contract $contract, User $actor): Contract
    {
        if (! $actor->can('update', $contract)) {
            throw ContractException::notAllowed('ce contrat');
        }

        return DB::transaction(function () use ($contract): Contract {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);

            if ($locked->status !== ContractStatus::Returned) {
                throw ContractException::invalidStatus($locked->status->label(), 'clôture');
            }

            $failures = $this->conditions->failures($locked);

            if ($failures !== []) {
                throw ContractException::cannotClose($failures);
            }

            $locked->fill(['status' => ContractStatus::Closed, 'closed_at' => CarbonImmutable::now()])->save();

            return $locked;
        });
    }
}
