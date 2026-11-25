<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Alerts;

use App\Enums\VehicleDocumentType;
use App\Enums\VehicleStatus;
use App\Filament\Resources\VehicleFinancings\VehicleFinancingResource;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\SentAlert;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleFinancing;
use App\Settings\NotificationSettings;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Alertes avant échéance (B-07, B-13, A-09), chaque jour à 06:05 :
 * - fin d'assurance et visite technique : dernière échéance connue du document,
 *   dans le délai réglé (un document renouvelé au-delà ne déclenche plus rien) ;
 * - fin de leasing et date de levée d'option d'un leasing en cours.
 *
 * Réglages `notifications.alerts` (A-09) : une alerte ne part que si elle est active
 * **et** si son délai est renseigné (vide = désactivée, Q90). Destinataires : profils
 * choisis ; un utilisateur d'agence ne reçoit que les alertes des véhicules localisés
 * dans ses agences ; comptes inactifs exclus ; les financements ne vont qu'au super
 * admin et à l'admin (portée par profil).
 *
 * Texte sans donnée client (RG-20) : véhicule, objet, date. Centre de notifications
 * (base) ; le push s'y ajoute en phase 9. Une seule alerte par objet et par échéance
 * (`sent_alerts`), quel que soit le nombre d'exécutions.
 *
 * @phpstan-type Due array{event: string, subject: Model, due_on: CarbonImmutable, title: string, body: string, url: string, agency_id: int|null}
 */
final class DueDateAlerts
{
    public function __construct(private readonly NotificationSettings $settings) {}

    /**
     * @return int nombre d'alertes envoyées
     */
    public function run(?CarbonImmutable $today = null): int
    {
        $today = ($today ?? CarbonImmutable::today((string) config('app.timezone')))->startOfDay();
        $sent = 0;

        foreach ($this->due($today) as $alert) {
            $sent += $this->send($alert, $today) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * @return list<Due>
     */
    private function due(CarbonImmutable $today): array
    {
        $alerts = [];

        foreach ([['insurance_expiry', VehicleDocumentType::Insurance], ['technical_inspection', VehicleDocumentType::TechnicalInspection]] as [$event, $type]) {
            $days = $this->window($event);
            if ($days === null) {
                continue;
            }

            // Dernière échéance connue par véhicule (document le plus lointain).
            $latest = VehicleDocument::query()
                ->where('type', $type->value)
                ->whereNotNull('valid_until')
                ->whereHas('vehicle', fn ($query) => $query->withoutGlobalScopes()->where('status', '!=', VehicleStatus::Retired->value))
                ->get()
                ->groupBy('vehicle_id')
                ->map(fn ($documents) => $documents->sortByDesc('valid_until')->first());

            $vehicles = Vehicle::query()->withoutGlobalScopes()->whereIn('id', $latest->keys()->all())->get()->keyBy('id');

            foreach ($latest as $vehicleId => $document) {
                $due = $document->valid_until;
                $vehicle = $vehicles->get($vehicleId);

                if ($vehicle === null || $due === null || $due->lessThan($today) || $due->greaterThan($today->addDays($days))) {
                    continue;
                }

                $alerts[] = [
                    'event' => $event,
                    'subject' => $vehicle,
                    'due_on' => $due,
                    'title' => "{$type->label()} : échéance le {$due->format('d/m/Y')}",
                    'body' => "Véhicule {$vehicle->label()}. Enregistrez le nouveau document avant cette date.",
                    'url' => VehicleResource::getUrl('view', ['record' => $vehicle], panel: 'admin'),
                    'agency_id' => $vehicle->current_agency_id,
                ];
            }
        }

        $days = $this->window('leasing_end');
        if ($days !== null) {
            $financings = VehicleFinancing::query()->with('vehicle')
                ->where('type', 'leasing')->whereNull('outcome')->get();

            foreach ($financings as $financing) {
                foreach (['fin du leasing' => $financing->ends_on, 'levée d\'option' => $financing->purchase_option_on] as $what => $due) {
                    if ($due === null || $due->lessThan($today) || $due->greaterThan($today->addDays($days))) {
                        continue;
                    }

                    $alerts[] = [
                        'event' => $what === 'fin du leasing' ? 'leasing_end' : 'leasing_option',
                        'subject' => $financing,
                        'due_on' => $due,
                        'title' => ucfirst($what)." le {$due->format('d/m/Y')}",
                        'body' => "Véhicule {$financing->vehicle->label()}, contrat {$financing->reference}.",
                        'url' => VehicleFinancingResource::getUrl('view', ['record' => $financing], panel: 'admin'),
                        'agency_id' => null,
                    ];
                }
            }
        }

        return $alerts;
    }

    private function window(string $event): ?int
    {
        $setting = $this->settings->alerts[$event] ?? null;

        if ($setting === null || ! $setting['enabled'] || $setting['days_before'] === null) {
            return null;
        }

        return max(0, (int) $setting['days_before']);
    }

    /**
     * @param  Due  $alert
     */
    private function send(array $alert, CarbonImmutable $today): bool
    {
        $already = SentAlert::query()
            ->where('event', $alert['event'])
            ->where('subject_type', $alert['subject']->getMorphClass())
            ->where('subject_id', $alert['subject']->getKey())
            ->where('due_on', $alert['due_on']->toDateString())
            ->exists();

        if ($already) {
            return false;
        }

        $recipients = $this->recipients($alert['event'] === 'leasing_option' ? 'leasing_end' : $alert['event'], $alert['agency_id']);

        try {
            SentAlert::query()->create([
                'event' => $alert['event'],
                'subject_type' => $alert['subject']->getMorphClass(),
                'subject_id' => $alert['subject']->getKey(),
                'due_on' => $alert['due_on'],
                'recipients_count' => count($recipients),
                'sent_at' => CarbonImmutable::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false; // exécution concurrente : déjà envoyée
        }

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title($alert['title'])
                ->body($alert['body'])
                ->warning()
                ->actions([Action::make('open')->label('Ouvrir')->url($alert['url'])])
                ->sendToDatabase($recipient);
        }

        return true;
    }

    /**
     * @return list<User>
     */
    private function recipients(string $event, ?int $agencyId): array
    {
        $roles = $this->settings->alerts[$event]['recipients'] ?? [];

        // Financements : jamais à un utilisateur d'agence (portée par profil).
        if ($event === 'leasing_end') {
            $roles = array_values(array_intersect($roles, [Role::SuperAdmin->value, Role::Admin->value]));
        }

        if ($roles === []) {
            return [];
        }

        return User::query()->role($roles)->where('is_active', true)->get()
            ->filter(fn (User $user): bool => $agencyId === null ? $user->canActOnAllAgencies() || ! $user->hasProfile(Role::AgencyUser) : $user->canActOnAgency($agencyId))
            ->values()
            ->all();
    }
}
