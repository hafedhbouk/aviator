<?php

namespace App\Filament\Resources\StampDutyRules\Pages;

use App\Domain\Tax\Actions\AddStampDutyRuleVersion;
use App\Domain\Tax\Exceptions\FiscalSetupException;
use App\Enums\FiscalValidationStatus;
use App\Filament\Forms\AmountField;
use App\Filament\Resources\StampDutyRules\StampDutyRuleResource;
use App\Models\FiscalReference;
use App\Models\StampDutyRule;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;

class ManageStampDutyRules extends ManageRecords
{
    protected static string $resource = StampDutyRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addVersion')
                ->label('Nouvelle version')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => auth()->user()?->can('create', StampDutyRule::class) ?? false)
                ->schema([
                    Select::make('rule_code')->label('Règle')->required()
                        ->options(fn (): array => StampDutyRule::query()->distinct()->orderBy('rule_code')->pluck('rule_code', 'rule_code')->all()),
                    AmountField::make('amount')->label('Montant')->required()->placeholder(null),
                    DatePicker::make('effective_from')->label('À partir du')->required(),
                    Select::make('validation_status')->label('Statut')->required()
                        ->options(collect(FiscalValidationStatus::cases())->mapWithKeys(fn (FiscalValidationStatus $s): array => [$s->value => $s->value])->all()),
                    Select::make('fiscal_reference_id')->label('Référence validée')
                        ->options(fn (): array => FiscalReference::query()->get()->mapWithKeys(fn (FiscalReference $r): array => [$r->id => $r->label()])->all()),
                ])
                ->action(function (array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();

                    try {
                        app(AddStampDutyRuleVersion::class)->handle((string) $data['rule_code'], $data, $actor);
                    } catch (FiscalSetupException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        throw new Halt;
                    }

                    Notification::make()->success()->title('Nouvelle version enregistrée')->send();
                }),
        ];
    }
}
