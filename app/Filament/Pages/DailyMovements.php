<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Crm\CustomerRiskGate;
use App\Domain\Fleet\DepartureGuard;
use App\Enums\ContractStatus;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Reservation;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Départs et retours du jour (écrans E4 et E5, parcours P3 et P4, étape 1), à l'heure de
 * Tunis, pour les agences de l'utilisateur (RG-13 : la portée par agence s'applique aux
 * requêtes). Départs : réservations confirmées sans contrat et contrats en brouillon qui
 * partent aujourd'hui ; retours : contrats en cours à rendre aujourd'hui ou en retard.
 * Badges : client en vigilance ou sur liste noire, véhicule à affecter, document du
 * véhicule à échéance pendant la location, retard. Aucune coordonnée du client (D13).
 */
class DailyMovements extends Page
{
    protected string $view = 'filament.pages.daily-movements';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Comptoir';

    protected static ?int $navigationSort = -1;

    protected static ?string $navigationLabel = 'Départs et retours du jour';

    protected static ?string $title = 'Départs et retours du jour';

    protected static ?string $slug = 'comptoir/jour';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Contract::class) ?? false;
    }

    /**
     * @return list<array{kind: string, number: string, customer: string, vehicle: string, at: string, badges: list<string>, url: string}>
     */
    public function departures(): array
    {
        [$start, $end] = $this->today();
        $rows = [];

        $reservations = Reservation::query()->with(['customer', 'vehicle'])
            ->where('status', ReservationStatus::Confirmed->value)
            ->whereBetween('starts_at', [$start, $end])
            ->orderBy('starts_at')->get();

        foreach ($reservations as $reservation) {
            $rows[] = $this->row('Réservation', $reservation->number, $reservation->customer, $reservation->vehicle?->label(), $reservation->starts_at,
                array_merge($reservation->vehicle_id === null ? ['Véhicule à affecter'] : [], $this->vehicleBadges($reservation)),
                ReservationResource::getUrl('view', ['record' => $reservation]));
        }

        $contracts = Contract::query()->with(['customer', 'vehicle'])
            ->where('status', ContractStatus::Draft->value)
            ->where('starts_at', '<=', $end)
            ->orderBy('starts_at')->get();

        foreach ($contracts as $contract) {
            $rows[] = $this->row('Contrat', $contract->number, $contract->customer, $contract->vehicle->label(), $contract->starts_at,
                $this->vehicleBadges($contract), ContractResource::getUrl('view', ['record' => $contract]));
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        return $rows;
    }

    /**
     * @return list<array{kind: string, number: string, customer: string, vehicle: string, at: string, badges: list<string>, url: string}>
     */
    public function returns(): array
    {
        [, $end] = $this->today();
        $now = CarbonImmutable::now();

        return Contract::query()->with(['customer', 'vehicle'])
            ->where('status', ContractStatus::Active->value)
            ->where('ends_at', '<=', $end)
            ->orderBy('ends_at')->get()
            ->map(fn (Contract $contract): array => $this->row('Contrat', $contract->number, $contract->customer, $contract->vehicle->label(), $contract->ends_at,
                $contract->ends_at->lessThan($now) ? ['En retard'] : [],
                ContractResource::getUrl('inspect', ['record' => $contract, 'type' => 'return'])))
            ->all();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function today(): array
    {
        $today = CarbonImmutable::now((string) config('app.timezone'));

        return [$today->startOfDay()->utc(), $today->endOfDay()->utc()];
    }

    /**
     * @param  list<string>  $badges
     * @return array{kind: string, number: string, customer: string, vehicle: string, at: string, badges: list<string>, url: string}
     */
    private function row(string $kind, string $number, Customer $customer, ?string $vehicle, CarbonImmutable $at, array $badges, string $url): array
    {
        $risk = app(CustomerRiskGate::class);

        if ($risk->isBlacklisted($customer)) {
            array_unshift($badges, 'Client sur liste noire');
        } elseif ($risk->isWatched($customer)) {
            array_unshift($badges, 'Client en vigilance');
        }

        return [
            'kind' => $kind,
            'number' => $number,
            'customer' => $customer->displayName(),
            'vehicle' => $vehicle ?? '—',
            'at' => $at->timezone((string) config('app.timezone'))->format('H:i'),
            'badges' => $badges,
            'url' => $url,
        ];
    }

    /**
     * @return list<string>
     */
    private function vehicleBadges(Reservation|Contract $document): array
    {
        $vehicle = $document->vehicle;

        if ($vehicle === null) {
            return [];
        }

        $guard = app(DepartureGuard::class);
        $badges = [];

        if ($guard->missingOn($vehicle, $document->starts_at) !== []) {
            $badges[] = 'Document du véhicule non valide au départ';
        } elseif ($guard->expiringDuring($vehicle, $document->starts_at, $document->ends_at) !== []) {
            $badges[] = 'Document du véhicule à échéance pendant la location';
        }

        return $badges;
    }
}
