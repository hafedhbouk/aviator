<?php

namespace App\Filament\Resources\RatePlans\Pages;

use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\RatePlans\RatePlanResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateRatePlan extends CreateRecord
{
    protected static string $resource = RatePlanResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveRatePlan::class)->handle(null, $data, $actor);
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
