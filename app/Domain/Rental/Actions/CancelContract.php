<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Approvals\Actions\CancelPendingApprovals;
use App\Domain\Availability\BlockRegistry;
use App\Domain\Fleet\ReservedStatusSync;
use App\Domain\Rental\Exceptions\ContractException;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Annulation d'un contrat **avant le départ** (D49), motif obligatoire : le contrat
 * numéroté est conservé (RG-11), le véhicule et la période sont libérés, les demandes
 * d'approbation en attente sont annulées. Une réservation convertie reste « convertie »
 * (historique) : une nouvelle réservation se saisit si le client revient.
 */
final class CancelContract
{
    public const MIN_REASON_LENGTH = 5;

    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly CancelPendingApprovals $approvals,
        private readonly ReservedStatusSync $reserved,
    ) {}

    public function handle(Contract $contract, string $reason, User $actor): Contract
    {
        if (! $actor->can('update', $contract)) {
            throw ContractException::notAllowed('ce contrat');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ContractException::reasonRequired(self::MIN_REASON_LENGTH);
        }

        $vehicleId = DB::transaction(function () use ($contract, $reason): int {
            $locked = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->id);

            if ($locked->status !== ContractStatus::Draft) {
                throw ContractException::invalidStatus($locked->status->label(), 'annulation');
            }

            $locked->fill(['status' => ContractStatus::Cancelled, 'cancellation_reason' => $reason, 'cancelled_at' => CarbonImmutable::now()]);
            $locked->save();

            $this->blocks->release($locked);
            $this->approvals->handle($locked, null, 'Contrat annulé avant le départ.');

            return $locked->vehicle_id;
        });

        $this->reserved->sync(Vehicle::query()->withoutGlobalScopes()->findOrFail($vehicleId));

        return $contract->refresh();
    }
}
