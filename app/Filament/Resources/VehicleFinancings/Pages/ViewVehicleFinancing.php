<?php

namespace App\Filament\Resources\VehicleFinancings\Pages;

use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\Exceptions\IllegalVehicleTransition;
use App\Domain\Fleet\Financing\Actions\SettleFinancing;
use App\Enums\FinancingOutcome;
use App\Enums\FinancingType;
use App\Filament\Resources\VehicleFinancings\VehicleFinancingResource;
use App\Models\User;
use App\Models\VehicleFinancing;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;

class ViewVehicleFinancing extends ViewRecord
{
    protected static string $resource = VehicleFinancingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (VehicleFinancing $record): bool => $record->isOpen()),
            Action::make('settle')
                ->label('Clôturer')
                ->icon(Heroicon::OutlinedFlag)
                ->visible(fn (VehicleFinancing $record): bool => $record->isOpen())
                ->schema(fn (VehicleFinancing $record): array => [
                    Select::make('outcome')->label('Issue')->required()
                        ->options(collect($record->type === FinancingType::Leasing
                            ? [FinancingOutcome::OptionExercised, FinancingOutcome::Returned]
                            : [FinancingOutcome::Repaid])->mapWithKeys(fn (FinancingOutcome $o): array => [$o->value => $o->label()])->all()),
                    DatePicker::make('on')->label('Date')->required()->maxDate(now()),
                ])
                ->modalDescription('Une restitution fait sortir le véhicule du parc (définitif).')
                ->requiresConfirmation()
                ->action(function (VehicleFinancing $record, array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();

                    try {
                        app(SettleFinancing::class)->handle($record, FinancingOutcome::from((string) $data['outcome']), CarbonImmutable::parse((string) $data['on']), $actor);
                    } catch (FleetException|IllegalVehicleTransition $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->persistent()->send();

                        throw new Halt;
                    }

                    Notification::make()->success()->title('Financement clôturé.')->send();
                }),
        ];
    }
}
