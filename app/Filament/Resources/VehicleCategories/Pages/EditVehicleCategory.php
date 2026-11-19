<?php

namespace App\Filament\Resources\VehicleCategories\Pages;

use App\Domain\Setup\Actions\SaveVehicleCategory;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\VehicleCategories\VehicleCategoryResource;
use App\Models\User;
use App\Models\VehicleCategory;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditVehicleCategory extends EditRecord
{
    protected static string $resource = VehicleCategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var VehicleCategory $record */
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveVehicleCategory::class)->handle($record, $data, $actor);
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
