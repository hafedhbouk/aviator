<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Domain\Fleet\Actions\SaveVehicle;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateVehicle extends CreateRecord
{
    protected static string $resource = VehicleResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveVehicle::class)->handle(null, $data, $actor);
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
