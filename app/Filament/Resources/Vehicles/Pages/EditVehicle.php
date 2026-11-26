<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Domain\Fleet\Actions\SaveVehicle;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditVehicle extends EditRecord
{
    protected static string $resource = VehicleResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Vehicle $record */
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveVehicle::class)->handle($record, $data, $actor);
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
