<?php

namespace App\Filament\Resources\VehicleFinancings\Pages;

use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\Financing\Actions\SaveFinancing;
use App\Filament\Resources\VehicleFinancings\VehicleFinancingResource;
use App\Models\User;
use App\Models\VehicleFinancing;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Modification d'un financement tant qu'aucune échéance n'est pointée : l'échéancier
 * est régénéré par le domaine.
 */
class EditVehicleFinancing extends EditRecord
{
    protected static string $resource = VehicleFinancingResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var VehicleFinancing $record */
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveFinancing::class)->handle($record->vehicle, $record, $data, $actor);
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->persistent()->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
