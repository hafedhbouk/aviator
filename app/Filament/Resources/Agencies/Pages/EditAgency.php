<?php

namespace App\Filament\Resources\Agencies\Pages;

use App\Domain\Setup\Actions\SaveAgency;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\Agencies\AgencyResource;
use App\Models\Agency;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditAgency extends EditRecord
{
    protected static string $resource = AgencyResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Agency $record */
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveAgency::class)->handle($record, $data, $actor);
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
