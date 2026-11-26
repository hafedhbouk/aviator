<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Availability\Actions\CloseReservation;
use App\Domain\Availability\Actions\SaveReservation;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\ReservationStatus;
use App\Models\ApprovalRequest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Effet d'une décision sur l'objet de la demande (D46). Une approbation ne change rien :
 * l'objet attendait déjà, avec sa remise ou en brouillon ; c'est l'agent qui poursuit
 * (départ, confirmation). Un refus :
 *
 * - remise : la remise est retirée et le devis recalculé (SaveReservation, mêmes
 *   contrôles qu'au comptoir) ;
 * - dérogation : le brouillon de réservation est annulé, motif à l'appui.
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

        if (! $subject instanceof Reservation) {
            return null;
        }

        try {
            match ($request->type) {
                ApprovalType::Discount => $this->removeDiscount($subject, $decider),
                ApprovalType::BlacklistOverride => $this->cancelDraft($subject, $decider),
            };
        } catch (Throwable $exception) {
            Log::warning('Effet d\'une décision d\'approbation non appliqué', ['approval_request_id' => $request->id, 'error' => $exception->getMessage()]);

            return 'La réservation n\'a pas pu être mise à jour automatiquement : corrigez-la avant le départ.';
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

    private function cancelDraft(Reservation $reservation, User $decider): void
    {
        if ($reservation->status !== ReservationStatus::Draft) {
            return;
        }

        app(CloseReservation::class)->cancel($reservation, 'Dérogation à la liste noire refusée par le super admin.', $decider);
    }
}
