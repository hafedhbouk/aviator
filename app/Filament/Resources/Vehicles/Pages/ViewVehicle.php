<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Domain\Fleet\Actions\RetireVehicle;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\Exceptions\IllegalVehicleTransition;
use App\Enums\RetirementReason;
use App\Enums\VehicleStatus;
use App\Filament\Forms\AmountField;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
}
