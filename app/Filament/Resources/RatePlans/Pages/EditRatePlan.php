<?php

namespace App\Filament\Resources\RatePlans\Pages;

use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\RatePlans\RatePlanResource;
use App\Models\RatePlan;
use App\Models\RatePlanLine;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditRatePlan extends EditRecord
{
    protected static string $resource = RatePlanResource::class;

    /**
     * Les tranches ne sont pas une relation du formulaire : elles sont remplies ici et
     * enregistrées ensemble par SaveRatePlan.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var RatePlan $plan */
        $plan = $this->getRecord();

        $data['lines'] = $plan->lines->map(fn (RatePlanLine $line): array => [
            'min_days' => $line->min_days,
            'max_days' => $line->max_days,
            'daily_amount' => $line->daily_amount,
        ])->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var RatePlan $record */
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveRatePlan::class)->handle($record, $data, $actor);
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
