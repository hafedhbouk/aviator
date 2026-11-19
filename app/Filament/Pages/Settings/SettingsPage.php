<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Domain\Setup\Actions\UpdateSettings;
use App\Domain\Setup\Exceptions\SetupException;
use App\Models\User;
use App\Support\Authorization\Permission;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelSettings\Settings;
use UnitEnum;

/**
 * Page de réglages d'un groupe spatie/laravel-settings, réservée au super admin. La
 * validation, l'enregistrement et le journal sont dans UpdateSettings.
 *
 * @property-read Schema $form
 */
abstract class SettingsPage extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @return class-string<Settings>
     */
    abstract protected static function settingsClass(): string;

    /**
     * @return list<Component>
     */
    abstract protected function fields(): array;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(app(static::settingsClass())->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components($this->fields());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Enregistrer')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var User $actor */
        $actor = auth()->user();
        // Validation du formulaire hors du try : ses erreurs portent déjà le préfixe « data. ».
        $state = $this->form->getState();

        try {
            app(UpdateSettings::class)->handle(app(static::settingsClass()), $state, $actor);
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => ["data.{$key}" => $messages])->all());
        }

        Notification::make()->success()->title('Réglages enregistrés')->send();
    }
}
