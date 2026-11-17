<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Administration\Actions\SaveUser;
use App\Domain\Administration\Exceptions\UserManagementException;
use App\Filament\Resources\Users\Actions\SendPasswordResetLinkAction;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SendPasswordResetLinkAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();

        $data['role'] = $user->profile()?->value;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveUser::class)->handle($record, $data, $actor);
        } catch (UserManagementException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
