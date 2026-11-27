<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Domain\Rental\Actions\ManageDamageFile;
use App\Enums\ApprovalStatus;
use App\Enums\ContractStatus;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractCharge;
use App\Models\ContractExtension;
use App\Models\DamageFile;
use App\Models\DamageFileDocument;
use App\Models\Driver;
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
                    TextEntry::make('closed_at')->label('Clôturé le')->formatStateUsing($at)->placeholder('—'),
                    TextEntry::make('cancellation_reason')->label('Motif d\'annulation')
                        ->visible(fn (Contract $record): bool => $record->status === ContractStatus::Cancelled),
                    $approval('discountApproval', 'Remise : approbation'),
                    $approval('overrideApproval', 'Dérogation à la liste noire'),
                ]),
                Section::make('Conducteurs')->columnSpanFull()->schema([
                    TextEntry::make('drivers_list')->label('Conducteurs autorisés')->listWithLineBreaks()->bulleted()
                        ->placeholder('Aucun conducteur : un conducteur principal est obligatoire avant le départ.')
                        ->state(fn (Contract $record): array => $record->drivers()->get()->map(function (Driver $driver): string {
                            $pivot = $driver->getRelationValue('pivot');
                            $checks = json_decode((string) $pivot?->getAttribute('checks'), true);
                            $warnings = is_array($checks) && is_array($checks['warnings'] ?? null) ? $checks['warnings'] : [];

                            return "{$driver->first_name} {$driver->last_name}"
                                .((bool) $pivot?->getAttribute('is_primary') ? ' — conducteur principal' : '')
                                .($warnings === [] ? '' : ' — '.implode(' ', $warnings));
                        })->all()),
                ]),
                Section::make('Prolongations')->columnSpanFull()
                    ->visible(fn (Contract $record): bool => $record->extensions()->exists())
                    ->schema([
                        TextEntry::make('extensions_list')->label('Prolongations (contrôle de disponibilité tracé)')->listWithLineBreaks()->bulleted()
                            ->state(fn (Contract $record): array => $record->extensions()->get()->map(fn (ContractExtension $extension): string => sprintf(
                                'Du %s au %s : %d jour(s), %s HT%s',
                                $extension->previous_ends_at->timezone((string) config('app.timezone'))->format('d/m/Y H:i'),
                                $extension->new_ends_at->timezone((string) config('app.timezone'))->format('d/m/Y H:i'),
                                $extension->added_days,
                                $extension->total_amount->format(),
                                $extension->discount_amount->isZero() ? '' : ' (remise '.$extension->discount_amount->format().')',
                            ))->all()),
                    ]),
                Section::make('Frais figés au retour (HT, sans taxe : qualifiés à la facture)')->columnSpanFull()
                    ->visible(fn (Contract $record): bool => $record->charges()->exists())
                    ->schema([
                        TextEntry::make('charges_list')->label('Frais')->listWithLineBreaks()->bulleted()
                            ->state(fn (Contract $record): array => $record->charges()->get()->map(fn (ContractCharge $charge): string => "{$charge->label} : {$charge->amount->format()}"
                                .(($charge->calculation['capped'] ?? false) ? ' (plafonné par la franchise)' : ''))->all()),
                    ]),
                Section::make('Dossiers de dommage (justificatifs, N2)')->columnSpanFull()
                    ->visible(fn (Contract $record): bool => $record->damageFiles()->exists())
                    ->schema([
                        TextEntry::make('damage_files_list')->label('Dossiers')->listWithLineBreaks()->bulleted()->html()
                            ->state(fn (Contract $record): array => $record->damageFiles()->with(['damage', 'documents'])->get()->map(function (DamageFile $file): string {
                                $documents = $file->documents->map(fn (DamageFileDocument $document): string => '<a class="underline" target="_blank" href="'
                                    .e(route('filament.admin.private-files.damage-document', ['document' => $document->id])).'">'.e($document->kind->label()).'</a>')->implode(', ');

                                return e("{$file->damage->zone->label()} : {$file->damage->description}")
                                    .' — '.($file->isComplete() ? 'complet' : 'incomplet : '.e(implode(', ', app(ManageDamageFile::class)->missing($file))).' à joindre')
                                    .($documents === '' ? '' : ' — '.$documents);
                            })->all()),
                    ]),
                Section::make('Avenants (D-11)')->columnSpanFull()
                    ->visible(fn (Contract $record): bool => $record->amendments()->exists())
                    ->schema([
                        TextEntry::make('amendments_list')->label('Avenants')->listWithLineBreaks()->bulleted()
                            ->state(fn (Contract $record): array => $record->amendments()->get()->map(fn (ContractAmendment $amendment): string => sprintf(
                                '%s, %s : %s (motif : %s)',
                                $amendment->created_at?->timezone((string) config('app.timezone'))->format('d/m/Y H:i') ?? '—',
                                $amendment->typeLabel(),
                                $amendment->type === ContractAmendment::NOTE ? (string) ($amendment->changes['note'] ?? '') : (string) ($amendment->changes['driver']['name'] ?? ''),
                                $amendment->reason,
                            ))->all()),
                    ]),
                Section::make('Conditions')->columnSpanFull()->columns(4)->schema([
                    TextEntry::make('mileage')->label('Kilométrage')
                        ->state(fn (Contract $record): string => $record->hasUnlimitedMileage()
                            ? 'Illimité'
                            : "{$record->included_km_per_day} km par jour, km supplémentaire ".($record->extra_km_amount?->format() ?? 'non tarifé')),
                    TextEntry::make('fuel')->label('Carburant')
                        ->state(fn (Contract $record): string => 'Même niveau au retour qu\'au départ ; '.(
                            $record->fuel_tank_liters === null || $record->fuel_liter_amount === null
                                ? 'carburant manquant non facturé (réservoir ou prix du litre non renseigné)'
                                : "réservoir {$record->fuel_tank_liters} L, litre {$record->fuel_liter_amount->format()}"
                                    .($record->fuel_service_amount === null ? '' : ", frais de service {$record->fuel_service_amount->format()}")
                        )),
                    TextEntry::make('late_hour_amount')->label('Tarif horaire du retard')->formatStateUsing($money)->placeholder('Non fixé'),
                    TextEntry::make('insurance_excess_amount')->label('Franchise')->formatStateUsing($money)->placeholder('Sans plafond'),
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
