<?php

namespace App\Filament\Resources\Options\Pages;

use App\Domain\Setup\Actions\SaveOption;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\Options\OptionResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateOption extends CreateRecord
{
    protected static string $resource = OptionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveOption::class)->handle(null, $data, $actor);
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
