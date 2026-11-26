<?php

namespace App\Filament\Resources\ApprovalRequests\Schemas;

use App\Domain\Crm\CustomerRiskGate;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Reservation;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Contexte d'une demande (P5, étape 4) : document, client et son niveau de
 * recommandation, historique de ses locations dans le réseau, données figées de la
 * demande (montant, pourcentage, seuil), motif saisi ; puis la décision.
 */
class ApprovalRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $at = fn ($state): string => $state?->timezone((string) config('app.timezone'))->format('d/m/Y H:i') ?? '—';
        $dt = fn ($state): string => is_string($state) ? Money::of($state)->format() : '—';
        $discount = fn (ApprovalRequest $record): bool => $record->type === ApprovalType::Discount;

        return $schema
            ->components([
                Section::make('Demande')->columnSpanFull()->columns(4)->schema([
                    TextEntry::make('type')->label('Demande')
                        ->formatStateUsing(fn (ApprovalType $state): string => $state->label()),
                    TextEntry::make('status')->label('État')->badge()
                        ->formatStateUsing(fn (ApprovalStatus $state): string => $state->label())
                        ->color(fn (ApprovalStatus $state): string => $state->color()),
                    TextEntry::make('agency.name')->label('Agence'),
                    TextEntry::make('requester.name')->label('Demandeur'),
                    TextEntry::make('created_at')->label('Demandée le')->formatStateUsing($at),
                    TextEntry::make('payload.document_number')->label('Document')
                        ->url(fn (ApprovalRequest $record): ?string => $record->subject instanceof Reservation
                            ? ReservationResource::getUrl('view', ['record' => $record->subject_id]) : null),
                    TextEntry::make('payload.starts_at')->label('Départ')
                        ->formatStateUsing(fn ($state): string => is_string($state) ? CarbonImmutable::parse($state)->timezone((string) config('app.timezone'))->format('d/m/Y H:i') : '—'),
                    TextEntry::make('payload.ends_at')->label('Retour')
                        ->formatStateUsing(fn ($state): string => is_string($state) ? CarbonImmutable::parse($state)->timezone((string) config('app.timezone'))->format('d/m/Y H:i') : '—'),
                    TextEntry::make('reason')->label('Motif du demandeur')->columnSpanFull(),
                ]),
                Section::make('Remise demandée')->columnSpanFull()->columns(4)->visible($discount)->schema([
                    TextEntry::make('payload.subtotal_amount')->label('Montant avant remise HT')->formatStateUsing($dt),
                    TextEntry::make('payload.discount_amount')->label('Remise HT')->formatStateUsing($dt),
                    TextEntry::make('payload.discount_percent')->label('Remise')
                        ->formatStateUsing(fn ($state): string => is_string($state) ? str_replace('.', ',', $state).' %' : '—'),
                    TextEntry::make('payload.threshold_percent')->label('Seuil sans approbation')
                        ->formatStateUsing(fn ($state): string => is_string($state) ? $state.' %' : '—'),
                    TextEntry::make('payload.total_excluding_tax')->label('Total HT après remise')->formatStateUsing($dt),
                ]),
                Section::make('Client')->columnSpanFull()->columns(3)->schema([
                    TextEntry::make('customer_name')->label('Client')
                        ->state(fn (ApprovalRequest $record): string => self::customer($record)?->displayName() ?? '—'),
                    TextEntry::make('customer_level')->label('Recommandation')
                        ->state(fn (ApprovalRequest $record): string => ($customer = self::customer($record)) === null
                            ? '—' : (app(CustomerRiskGate::class)->levelOf($customer)?->label() ?? 'Aucune')),
                    TextEntry::make('customer_history')->label('Locations dans le réseau')
                        ->state(fn (ApprovalRequest $record): string => ($customer = self::customer($record)) === null ? '—' : (string) Reservation::query()->withoutGlobalScopes()
                            ->where('customer_id', $customer->id)
                            ->whereIn('status', [ReservationStatus::Confirmed->value, ReservationStatus::Converted->value])
                            ->count().' réservation(s) confirmée(s) ou convertie(s)'),
                ]),
                Section::make('Décision')->columnSpanFull()->columns(3)
                    ->visible(fn (ApprovalRequest $record): bool => ! $record->isPending())
                    ->schema([
                        TextEntry::make('decider.name')->label('Par')->placeholder('Annulation automatique'),
                        TextEntry::make('decided_at')->label('Le')->formatStateUsing($at),
                        TextEntry::make('comment')->label('Commentaire')->placeholder('—'),
                    ]),
            ]);
    }

    private static function customer(ApprovalRequest $record): ?Customer
    {
        $subject = $record->subject;

        return $subject instanceof Reservation ? Customer::query()->withoutGlobalScopes()->find($subject->customer_id) : null;
    }
}
