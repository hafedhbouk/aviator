<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Enums\ApprovalStatus;
use App\Enums\ContractStatus;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Contract;
use App\Support\Money\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Fiche d'un contrat, en lecture : conditions et montants figés à la création.
 */
class ContractInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $at = fn ($state): string => $state?->timezone((string) config('app.timezone'))->format('d/m/Y H:i') ?? '—';
        $money = fn (?Money $state): string => $state?->format() ?? '—';
        $approval = fn (string $field, string $label): TextEntry => TextEntry::make("{$field}.status")->label($label)->badge()
            ->formatStateUsing(fn (ApprovalStatus $state): string => $state->label())
            ->color(fn (ApprovalStatus $state): string => $state->color())
            ->visible(fn (Contract $record): bool => $record->getAttribute("{$field}_id") !== null);

        return $schema
            ->components([
                Section::make('Contrat')->columnSpanFull()->columns(4)->schema([
                    TextEntry::make('number')->label('Numéro'),
                    TextEntry::make('status')->label('Statut')->badge()
                        ->formatStateUsing(fn (ContractStatus $state): string => $state->label())
                        ->color(fn (ContractStatus $state): string => $state->color()),
                    TextEntry::make('customer_name')->label('Client')
                        ->state(fn (Contract $record): string => $record->customer->displayName()),
                    TextEntry::make('agency.name')->label('Agence de départ et de retour'),
                    TextEntry::make('vehicle_label')->label('Véhicule')
                        ->state(fn (Contract $record): string => $record->vehicle->label()),
                    TextEntry::make('starts_at')->label('Départ prévu')->formatStateUsing($at),
                    TextEntry::make('ends_at')->label('Retour prévu')->formatStateUsing($at),
                    TextEntry::make('reservation.number')->label('Réservation')->placeholder('Saisie au comptoir')
                        ->url(fn (Contract $record): ?string => $record->reservation_id === null ? null : ReservationResource::getUrl('view', ['record' => $record->reservation_id])),
                    TextEntry::make('departed_at')->label('Parti le')->formatStateUsing($at)->placeholder('—'),
                    TextEntry::make('returned_at')->label('Restitué le')->formatStateUsing($at)->placeholder('—'),
                    TextEntry::make('cancellation_reason')->label('Motif d\'annulation')
                        ->visible(fn (Contract $record): bool => $record->status === ContractStatus::Cancelled),
                    $approval('discountApproval', 'Remise : approbation'),
                    $approval('overrideApproval', 'Dérogation à la liste noire'),
                ]),
                Section::make('Conditions')->columnSpanFull()->columns(4)->schema([
                    TextEntry::make('mileage')->label('Kilométrage')
                        ->state(fn (Contract $record): string => $record->hasUnlimitedMileage()
                            ? 'Illimité'
                            : "{$record->included_km_per_day} km par jour, km supplémentaire ".($record->extra_km_amount?->format() ?? 'non tarifé')),
                    TextEntry::make('fuel')->label('Carburant')->state('Même niveau au retour qu\'au départ'),
                    TextEntry::make('daily_rate_amount')->label('Tarif journalier (prolongation, retard)')->formatStateUsing($money),
                    TextEntry::make('deposit_expected_amount')->label('Caution prévue')->formatStateUsing($money)->placeholder('À saisir'),
                ]),
                Section::make('Montants figés (HT)')->columnSpanFull()->columns(4)->schema([
                    TextEntry::make('rental_amount')->label('Location')->formatStateUsing($money),
                    TextEntry::make('options_amount')->label('Options')->formatStateUsing($money),
                    TextEntry::make('discount_amount')->label('Remise')->formatStateUsing($money),
                    TextEntry::make('total_amount')->label('Total HT')->formatStateUsing($money),
                ]),
            ]);
    }
}
