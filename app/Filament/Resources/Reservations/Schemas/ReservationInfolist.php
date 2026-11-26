<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Enums\ApprovalStatus;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\Money\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Fiche d'une réservation, en lecture : le devis affiché est le devis figé.
 */
class ReservationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $at = fn ($state): string => $state?->timezone((string) config('app.timezone'))->format('d/m/Y H:i') ?? '—';

        return $schema
            ->components([
                Section::make('Réservation')->columnSpanFull()->columns(4)->schema([
                    TextEntry::make('number')->label('Numéro'),
                    TextEntry::make('status')->label('Statut')->badge()
                        ->formatStateUsing(fn (ReservationStatus $state): string => $state->label())
                        ->color(fn (ReservationStatus $state): string => $state->color()),
                    TextEntry::make('customer_name')->label('Client')
                        ->state(fn (Reservation $record): string => $record->customer->displayName()),
                    TextEntry::make('agency.name')->label('Agence de départ et de retour'),
                    TextEntry::make('starts_at')->label('Départ')->formatStateUsing($at),
                    TextEntry::make('ends_at')->label('Retour')->formatStateUsing($at),
                    TextEntry::make('category.name')->label('Catégorie'),
                    TextEntry::make('vehicle_label')->label('Véhicule')
                        ->state(fn (Reservation $record): string => $record->vehicle?->label() ?? 'À affecter au plus tard au départ'),
                    TextEntry::make('cancellation_reason')->label('Motif d\'annulation')->placeholder('—')
                        ->visible(fn (Reservation $record): bool => $record->status === ReservationStatus::Cancelled),
                    TextEntry::make('expires_at')->label('Expire le')->formatStateUsing($at)
                        ->visible(fn (Reservation $record): bool => $record->expires_at !== null),
                    TextEntry::make('discountApproval.status')->label('Remise : approbation')->badge()
                        ->formatStateUsing(fn (ApprovalStatus $state): string => $state->label())
                        ->color(fn (ApprovalStatus $state): string => $state->color())
                        ->visible(fn (Reservation $record): bool => $record->discount_approval_id !== null),
                    TextEntry::make('overrideApproval.status')->label('Dérogation à la liste noire')->badge()
                        ->formatStateUsing(fn (ApprovalStatus $state): string => $state->label())
                        ->color(fn (ApprovalStatus $state): string => $state->color())
                        ->visible(fn (Reservation $record): bool => $record->override_approval_id !== null),
                ]),
                Section::make('Devis figé')->columnSpanFull()->columns(4)->schema([
                    TextEntry::make('quote.billable_days')->label('Jours facturés'),
                    TextEntry::make('quote.rental_amount')->label('Location HT')->formatStateUsing(fn ($state): string => self::dt($state)),
                    TextEntry::make('quote.options_amount')->label('Options HT')->formatStateUsing(fn ($state): string => self::dt($state)),
                    TextEntry::make('quote.discount_amount')->label('Remise HT')->formatStateUsing(fn ($state): string => self::dt($state)),
                    TextEntry::make('quote.total_excluding_tax')->label('Total HT')->formatStateUsing(fn ($state): string => self::dt($state)),
                    TextEntry::make('quote.taxes.total_including_tax')->label('Total TTC')->formatStateUsing(fn ($state): string => self::dt($state))
                        ->placeholder('—'),
                    TextEntry::make('quote.taxes.net_to_pay')->label('Net à payer (timbre compris)')->formatStateUsing(fn ($state): string => self::dt($state))
                        ->placeholder('—'),
                    TextEntry::make('quote.tax_notice')->label('Taxes')->placeholder('Calculées selon le référentiel validé')->columnSpan(2),
                ]),
            ]);
    }

    private static function dt(mixed $state): string
    {
        return is_string($state) ? Money::of($state)->format() : '—';
    }
}
