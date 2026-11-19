<?php

namespace App\Filament\Resources\VehicleCategories\Pages;

use App\Domain\Setup\Actions\SaveVehicleCategory;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\VehicleCategories\VehicleCategoryResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateVehicleCategory extends CreateRecord
{
    protected static string $resource = VehicleCategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveVehicleCategory::class)->handle(null, $data, $actor);
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
