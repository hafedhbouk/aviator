<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Administration\Actions\SaveUser;
use App\Domain\Administration\Exceptions\UserManagementException;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Auth\PasswordResetLinkSender;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveUser::class)->handle(null, $data, $actor);
        } catch (UserManagementException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        app(PasswordResetLinkSender::class)->send($user);

        Notification::make()
            ->success()
            ->title('Compte créé. Un lien pour choisir son mot de passe a été envoyé au titulaire.')
            ->send();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
