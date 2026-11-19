<?php

namespace App\Filament\Resources\Options\Pages;

use App\Domain\Setup\Actions\SaveOption;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\Options\OptionResource;
use App\Models\Option;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditOption extends EditRecord
{
    protected static string $resource = OptionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Option $record */
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveOption::class)->handle($record, $data, $actor);
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
