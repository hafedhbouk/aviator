<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Domain\Fleet\Actions\CorrectMileage;
use App\Domain\Fleet\Actions\EndMaintenance;
use App\Domain\Fleet\Actions\RecordMileage;
use App\Domain\Fleet\Actions\RetireVehicle;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\Exceptions\IllegalVehicleTransition;
use App\Enums\RetirementReason;
use App\Enums\VehicleStatus;
use App\Filament\Forms\AmountField;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Authorization\Permission;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;

class ViewVehicle extends ViewRecord
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Vehicle $record): bool => $record->status !== VehicleStatus::Retired && (auth()->user()?->can('update', $record) ?? false)),
            Action::make('recordMileage')
                ->label('Relever le kilométrage')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->visible(fn (Vehicle $record): bool => $record->status !== VehicleStatus::Retired && (auth()->user()?->can('update', $record) ?? false))
                ->schema(fn (Vehicle $record): array => [
                    TextInput::make('mileage')->label('Kilométrage (km)')->required()->integer()
                        ->minValue($record->mileage)->maxValue(RecordMileage::MAX_MILEAGE)
                        ->helperText('Dernier relevé : '.number_format($record->mileage, 0, ',', ' ').' km. Une baisse passe par une correction.'),
                ])
                ->action(function (Vehicle $record, array $data): void {
                    $this->mileageAction(fn (User $actor) => app(RecordMileage::class)->handle($record, (int) $data['mileage'], $actor));
                }),
            Action::make('correctMileage')
                ->label('Corriger le kilométrage')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('warning')
                ->visible(fn (Vehicle $record): bool => $record->status !== VehicleStatus::Retired && (auth()->user()?->can('update', $record) ?? false))
                ->modalDescription('Correction tracée : la valeur précédente et le motif sont conservés dans l\'historique.')
                ->schema([
                    TextInput::make('mileage')->label('Kilométrage corrigé (km)')->required()->integer()
                        ->minValue(0)->maxValue(RecordMileage::MAX_MILEAGE),
                    Textarea::make('reason')->label('Motif')->required()->minLength(CorrectMileage::MIN_REASON_LENGTH)->maxLength(255)
                        ->placeholder('Erreur de saisie au retour du contrat…'),
                ])
                ->action(function (Vehicle $record, array $data): void {
                    $this->mileageAction(fn (User $actor) => app(CorrectMileage::class)->handle($record, (int) $data['mileage'], (string) $data['reason'], $actor));
                }),
            Action::make('endMaintenance')
                ->label('Fin d\'intervention')
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->color('success')
                ->visible(fn (Vehicle $record): bool => $record->status === VehicleStatus::Maintenance
                    && (auth()->user()?->hasPermissionTo(Permission::FleetManage->value) ?? false))
                ->modalDescription('Le véhicule redevient « Disponible ». Les ordres de maintenance arrivent en phase 8 (D47).')
                ->schema([
                    Textarea::make('reason')->label('Intervention réalisée (obligatoire)')->required()
                        ->minLength(EndMaintenance::MIN_REASON_LENGTH)->maxLength(255),
                ])
                ->action(function (Vehicle $record, array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();

                    try {
                        app(EndMaintenance::class)->handle($record, (string) $data['reason'], $actor);
                    } catch (FleetException|IllegalVehicleTransition $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        throw new Halt;
                    }

                    Notification::make()->success()->title('Véhicule de nouveau disponible.')->send();
                    $this->refreshFormData(['status_label']);
                }),
            Action::make('retire')
                ->label('Sortir du parc')
                ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('La sortie du parc est définitive : le véhicule ne pourra plus être loué ni modifié.')
                ->visible(fn (Vehicle $record): bool => $record->status !== VehicleStatus::Retired && (auth()->user()?->can('update', $record) ?? false))
                ->schema([
                    DatePicker::make('retired_on')->label('Date de sortie')->required()->maxDate(now()),
                    Select::make('retirement_reason')->label('Motif')->required()
                        ->options(collect(RetirementReason::cases())->mapWithKeys(fn (RetirementReason $r): array => [$r->value => $r->label()])->all()),
                    AmountField::make('disposal_amount')->label('Prix de cession (pour information)'),
                ])
                ->action(function (Vehicle $record, array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();

                    try {
                        app(RetireVehicle::class)->handle($record, $data, $actor);
                    } catch (FleetException|IllegalVehicleTransition $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        throw new Halt;
                    }

                    Notification::make()->success()->title('Véhicule sorti du parc.')->send();
                    $this->refreshFormData(['status_label']);
                }),
        ];
    }

    /**
     * @param  callable(User): mixed  $write
     */
    private function mileageAction(callable $write): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            $write($actor);
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw new Halt;
        }

        Notification::make()->success()->title('Kilométrage enregistré.')->send();
        $this->record->refresh();
        $this->refreshFormData(['mileage_label']);
    }
}
