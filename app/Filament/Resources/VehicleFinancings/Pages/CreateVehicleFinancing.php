<?php

namespace App\Filament\Resources\VehicleFinancings\Pages;

use App\Domain\Fleet\Exceptions\FleetException;
use App\Domain\Fleet\Financing\Actions\SaveFinancing;
use App\Filament\Resources\VehicleFinancings\VehicleFinancingResource;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateVehicleFinancing extends CreateRecord
{
    protected static string $resource = VehicleFinancingResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();
        $vehicle = Vehicle::query()->withoutGlobalScopes()->findOrFail((int) $data['vehicle_id']);

        try {
            return app(SaveFinancing::class)->handle($vehicle, null, $data, $actor);
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->persistent()->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
