<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\Exceptions\ContractException;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Retire un conducteur d'un contrat en brouillon. La fiche du conducteur est conservée
 * (elle appartient au client).
 */
final class RemoveContractDriver
{
    public function handle(Contract $contract, Driver $driver, User $actor): void
    {
        if (! $actor->can('update', $contract)) {
            throw ContractException::notAllowed('ce contrat');
        }

        DB::transaction(function () use ($contract, $driver, $actor): void {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);

            if ($locked->status !== ContractStatus::Draft) {
                throw ContractException::invalidStatus($locked->status->label(), 'modification des conducteurs');
            }

            if ($locked->drivers()->detach($driver->id) > 0) {
                activity('audit')->causedBy($actor)->performedOn($locked)->event('contract_driver_removed')
                    ->withProperties(['driver_id' => $driver->id])
                    ->log('Conducteur retiré du contrat');
            }
        });
    }
}
