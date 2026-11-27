<?php

declare(strict_types=1);

namespace App\Domain\Rental\Alerts;

use App\Enums\ContractStatus;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use App\Models\SentAlert;
use App\Models\User;
use App\Settings\NotificationSettings;
use App\Settings\PricingSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Alerte « Retard de restitution » (A-09, phase 5, lot L12) : un contrat en cours dont le
 * retour prévu, tolérance comprise (Q14), est dépassé. Envoyée **une seule fois** par
 * échéance (`sent_alerts`, unique ; une prolongation crée une nouvelle échéance), aux
 * profils choisis au paramétrage (`notifications.alerts.late_return`) **qui agissent sur
 * l'agence du contrat** (RG-13). Canal `database` ; le push arrive en phase 9.
 *
 * Texte générique : ni nom du client, ni immatriculation, ni montant (RG-20, J-14) ; le
 * détail se lit après connexion, en ouvrant le contrat.
 */
final class LateReturnAlerts
{
    public const EVENT = 'late_return';

    public const TITLE = 'Retour en retard';

    public const BODY = 'Un véhicule n\'est pas revenu à l\'heure prévue.';

    public function __construct(
        private readonly NotificationSettings $notifications,
        private readonly PricingSettings $pricing,
    ) {}

    /**
     * @return int nombre d'alertes envoyées
     */
    public function run(?CarbonImmutable $now = null): int
    {
        $setting = $this->notifications->alerts[self::EVENT] ?? null;

        if ($setting === null || ! $setting['enabled']) {
            return 0;
        }

        $now ??= CarbonImmutable::now();
        $limit = $now->subMinutes(max(0, $this->pricing->late_tolerance_minutes));
        $sent = 0;

        Contract::query()->withoutGlobalScopes()
            ->where('status', ContractStatus::Active->value)
            ->where('ends_at', '<', $limit)
            ->orderBy('id')
            ->each(function (Contract $contract) use ($setting, $now, &$sent): void {
                $sent += $this->send($contract, $setting['recipients'], $now) ? 1 : 0;
            });

        return $sent;
    }

    /**
     * @param  list<string>  $roles  profils destinataires choisis au paramétrage
     */
    private function send(Contract $contract, array $roles, CarbonImmutable $now): bool
    {
        // Aucun profil choisi : l'échéance est tracée, personne n'est notifié.
        $recipients = $roles === [] ? collect() : User::query()->role($roles)->where('is_active', true)->get()
            ->filter(fn (User $user): bool => $user->canActOnAgency($contract->agency_id))
            ->values();

        try {
            SentAlert::query()->create([
                'event' => self::EVENT,
                'subject_type' => $contract->getMorphClass(),
                'subject_id' => $contract->id,
                'due_on' => $contract->ends_at->timezone((string) config('app.timezone'))->toDateString(),
                'recipients_count' => $recipients->count(),
                'sent_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false; // déjà envoyée pour cette échéance
        }

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title(self::TITLE)
                ->body(self::BODY)
                ->danger()
                ->actions([Action::make('open')->label('Ouvrir le contrat')->url(ContractResource::getUrl('view', ['record' => $contract->id]))])
                ->sendToDatabase($recipient);
        }

        return true;
    }
}
