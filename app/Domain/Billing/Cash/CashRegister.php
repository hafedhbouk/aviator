<?php

declare(strict_types=1);

namespace App\Domain\Billing\Cash;

use App\Domain\Billing\Exceptions\CashException;
use App\Models\Agency;
use App\Models\CashSession;
use App\Models\CreditNoteSettlement;
use App\Models\Deposit;
use App\Models\DepositMovement;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money\AmountInput;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Journal de caisse par agence et par jour (E-09, Q55, D57) :
 *
 * - ouverture avec un fond de caisse, une seule caisse par agence et par jour (index
 *   unique), et seulement quand la caisse précédente de l'agence est clôturée ;
 * - encaissements en espèces et remboursements d'avoirs en espèces rattachés à la caisse
 *   ouverte du jour (openFor(), sous verrou de la caisse) ; refusés sans caisse ouverte ;
 * - clôture par l'utilisateur d'agence : montant compté, montant attendu (fond + espèces
 *   reçues − espèces remboursées, encaissements annulés exclus), écart ; un écart non nul
 *   exige un motif, visible de l'admin (Q55, valeur par défaut).
 */
final class CashRegister
{
    public const MIN_REASON_LENGTH = 10;

    /** Mode de paiement qui passe par la caisse. */
    public const CASH_METHOD = 'cash';

    public function open(Agency $agency, mixed $openingAmount, User $actor): CashSession
    {
        if (! $actor->can('open', [CashSession::class, $agency->id])) {
            throw CashException::notAllowed();
        }

        $amount = $this->amount($openingAmount, 'fond de caisse', allowZero: true);
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($agency, $amount, $actor, $now): CashSession {
            Agency::query()->lockForUpdate()->findOrFail($agency->id);

            $previous = CashSession::query()->withoutGlobalScopes()->where('agency_id', $agency->id)->whereNull('closed_at')->lockForUpdate()->first();

            if ($previous !== null) {
                throw CashException::previousOpen($previous->business_date->format('d/m/Y'));
            }

            try {
                return CashSession::query()->create([
                    'agency_id' => $agency->id,
                    'business_date' => self::businessDate($now),
                    'opened_at' => $now,
                    'opened_by' => $actor->id,
                    'opening_amount' => $amount,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CashException::alreadyOpenedToday();
            }
        });
    }

    /**
     * Caisse ouverte du jour de l'agence, **verrouillée** : à appeler dans la transaction
     * qui enregistre le mouvement en espèces.
     */
    public function openFor(int $agencyId, ?CarbonImmutable $at = null): CashSession
    {
        $session = CashSession::query()->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('business_date', self::businessDate($at ?? CarbonImmutable::now()))
            ->whereNull('closed_at')
            ->lockForUpdate()
            ->first();

        return $session ?? throw CashException::noOpenSession();
    }

    public function close(CashSession $session, mixed $countedAmount, ?string $reason, User $actor): CashSession
    {
        if (! $actor->can('close', $session)) {
            throw CashException::notAllowed();
        }

        $counted = $this->amount($countedAmount, 'montant compté', allowZero: true);
        $reason = $reason === null ? '' : trim($reason);

        return DB::transaction(function () use ($session, $counted, $reason, $actor): CashSession {
            $locked = CashSession::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($session->id);

            if (! $locked->isOpen()) {
                throw CashException::alreadyClosed();
            }

            $expected = self::expected($locked);
            $difference = $counted->minus($expected);

            if (! $difference->isZero() && mb_strlen($reason) < self::MIN_REASON_LENGTH) {
                throw CashException::differenceReasonRequired($difference->format(), self::MIN_REASON_LENGTH);
            }

            $locked->fill([
                'closed_at' => CarbonImmutable::now(),
                'closed_by' => $actor->id,
                'expected_amount' => $expected,
                'counted_amount' => $counted,
                'difference_amount' => $difference,
                'difference_reason' => $difference->isZero() ? null : mb_substr($reason, 0, 500),
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Montant attendu : fond de caisse + espèces reçues (encaissements non annulés,
     * cautions) − espèces rendues (remboursements d'avoirs, restitutions de cautions).
     */
    public static function expected(CashSession $session): Money
    {
        $in = Payment::query()->withoutGlobalScopes()->where('cash_session_id', $session->id)->where('status', Payment::RECORDED)->get()
            ->reduce(fn (Money $sum, Payment $payment): Money => $sum->plus($payment->amount), Money::zero());
        $out = CreditNoteSettlement::query()->where('cash_session_id', $session->id)->get()
            ->reduce(fn (Money $sum, CreditNoteSettlement $refund): Money => $sum->plus($refund->amount), Money::zero());

        // Cautions en espèces (L10) : reçues dans la caisse, restituées depuis elle.
        $depositsIn = Deposit::query()->withoutGlobalScopes()->where('cash_session_id', $session->id)->get()
            ->reduce(fn (Money $sum, Deposit $deposit): Money => $sum->plus($deposit->amount_received), Money::zero());
        $depositsOut = DepositMovement::query()->where('cash_session_id', $session->id)->where('type', DepositMovement::REFUND)->get()
            ->reduce(fn (Money $sum, DepositMovement $movement): Money => $sum->plus($movement->amount), Money::zero());

        return $session->opening_amount->plus($in)->minus($out)->plus($depositsIn)->minus($depositsOut);
    }

    public static function businessDate(CarbonImmutable $at): string
    {
        return $at->timezone((string) config('app.timezone'))->format('Y-m-d');
    }

    private function amount(mixed $value, string $label, bool $allowZero): Money
    {
        try {
            $amount = AmountInput::parse($value);
        } catch (InvalidArgumentException $exception) {
            throw CashException::invalidAmount($label, $exception->getMessage());
        }

        if ($amount === null || $amount->isNegative() || (! $allowZero && $amount->isZero())) {
            throw CashException::invalidAmount($label, 'saisissez un montant positif ou nul, au millime.');
        }

        return $amount;
    }
}
