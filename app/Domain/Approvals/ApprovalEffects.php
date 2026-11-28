<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Availability\Actions\CloseReservation;
use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Rental\Actions\CancelContract;
use App\Domain\Rental\Actions\RemoveContractDiscount;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\ContractStatus;
use App\Enums\ReservationStatus;
use App\Models\ApprovalRequest;
use App\Models\Contract;
use App\Models\ContractExtension;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Effet d'une décision sur l'objet de la demande (D46). Une approbation ne change rien :
 * l'objet attendait déjà, avec sa remise ou en brouillon ; c'est l'agent qui poursuit
 * (départ, confirmation). Un refus :
 *
 * - remise : la remise est retirée et le devis recalculé (SaveReservation, mêmes
 *   contrôles qu'au comptoir) ;
 * - dérogation : le brouillon de réservation est annulé, motif à l'appui ;
 * - retenue sur caution : rien à défaire, la retenue n'est appliquée qu'après une
 *   approbation (ApplyRetention), la caution reste entière.
 *
 * Si l'effet échoue (grille tarifaire supprimée entre-temps, réservation déjà
 * annulée…), la décision reste acquise et le demandeur est prévenu : la remise refusée
 * bloque toujours le départ (ApprovalGate).
 */
final class ApprovalEffects
{
    /**
     * @return string|null message d'avertissement pour le demandeur
     */
    public function apply(ApprovalRequest $request, User $decider): ?string
    {
        if ($request->status !== ApprovalStatus::Rejected) {
            return null;
        }

        $subject = $request->subject;

        // Réservation déjà convertie : l'effet porte sur son contrat en brouillon.
        if ($subject instanceof Reservation && $subject->status === ReservationStatus::Converted) {
            $subject = Contract::query()->withoutGlobalScopes()->where('reservation_id', $subject->id)->first() ?? $subject;
        }

        try {
            if ($subject instanceof Reservation) {
                match ($request->type) {
                    ApprovalType::Discount => $this->removeDiscount($subject, $decider),
                    ApprovalType::BlacklistOverride => $this->cancelDraft($subject, $decider),
                    ApprovalType::Retention => null,
                };
            } elseif ($subject instanceof ContractExtension) {
                if ($request->type === ApprovalType::Discount) {
                    $this->removeExtensionDiscount($subject);
                }
            } elseif ($subject instanceof Contract) {
                match ($request->type) {
                    ApprovalType::Discount => $this->removeContractDiscount($subject, $decider),
                    ApprovalType::BlacklistOverride => $this->cancelContract($subject, $decider),
                    ApprovalType::Retention => null,
                };
            }
        } catch (Throwable $exception) {
            Log::warning('Effet d\'une décision d\'approbation non appliqué', ['approval_request_id' => $request->id, 'error' => $exception->getMessage()]);

            return 'Le document n\'a pas pu être mis à jour automatiquement : corrigez-le avant le départ.';
        }

        return null;
    }

    private function removeDiscount(Reservation $reservation, User $decider): void
    {
        if (! in_array($reservation->status, ReservationStatus::open(), true) || $reservation->discount_amount->isZero()) {
            return;
        }

        app(SaveReservation::class)->handle($reservation, ['discount_amount' => '0'], $decider);
    }

    /**
     * Remise d'une prolongation refusée : retirée de la prolongation et du contrat, sous
     * verrou du contrat (Q93).
     */
    private function removeExtensionDiscount(ContractExtension $extension): void
    {
        DB::transaction(function () use ($extension): void {
            $contract = Contract::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($extension->contract_id);
            $locked = ContractExtension::query()->lockForUpdate()->findOrFail($extension->id);

            if ($locked->discount_amount->isZero()) {
                return;
            }

            $contract->fill([
                'discount_amount' => $contract->discount_amount->minus($locked->discount_amount),
                'total_amount' => $contract->total_amount->plus($locked->discount_amount),
            ])->save();

            $locked->fill([
                'total_amount' => $locked->total_amount->plus($locked->discount_amount),
                'discount_amount' => Money::zero(),
                'discount_approval_id' => null,
            ])->save();
        });
    }

    private function removeContractDiscount(Contract $contract, User $decider): void
    {
        if ($contract->status !== ContractStatus::Draft) {
            return;
        }

        app(RemoveContractDiscount::class)->handle($contract, $decider);
    }

    private function cancelContract(Contract $contract, User $decider): void
    {
        if ($contract->status !== ContractStatus::Draft) {
            return;
        }

        app(CancelContract::class)->handle($contract, 'Dérogation à la liste noire refusée par le super admin.', $decider);
    }

    private function cancelDraft(Reservation $reservation, User $decider): void
    {
        if ($reservation->status !== ReservationStatus::Draft) {
            return;
        }

        app(CloseReservation::class)->cancel($reservation, 'Dérogation à la liste noire refusée par le super admin.', $decider);
    }
}
