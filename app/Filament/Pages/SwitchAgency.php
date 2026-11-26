<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Agency;
use App\Models\User;
use App\Support\Agency\AgencyAccessException;
use App\Support\Agency\CurrentAgency;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;

/**
 * Choix de l'agence active (Q69, report de la phase 2, lot L13) : un utilisateur
 * d'agence qui remplace un collègue choisit l'agence de ses nouveaux documents parmi
 * celles où il peut agir aujourd'hui. La règle est dans CurrentAgency::switchTo.
 *
 * Entrée du menu utilisateur, visible seulement s'il y a un choix à faire.
 *
 * @property-read Schema $form
 */
class SwitchAgency extends Page
{
    protected static ?string $title = 'Agence active';

    protected static ?string $slug = 'agence-active';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && count(self::choices($user)) > 1;
    }

    /**
     * Agences actives où l'utilisateur peut agir aujourd'hui ; vide pour le super
     * admin et l'admin, qui n'ont pas d'agence active.
     *
     * @return array<int, string>
     */
    public static function choices(User $user): array
    {
        if ($user->canActOnAllAgencies()) {
            return [];
        }

        return Agency::query()->where('is_active', true)
            ->whereIn('id', $user->authorizedAgencyIds())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Libellé du menu utilisateur : agence en cours.
     */
    public static function menuLabel(User $user): string
    {
        $current = app(CurrentAgency::class)->id($user);

        return 'Agence active : '.(self::choices($user)[$current] ?? '—');
    }

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $this->form->fill(['agency_id' => app(CurrentAgency::class)->id($user)]);
    }

    public function form(Schema $schema): Schema
    {
        /** @var User $user */
        $user = auth()->user();

        return $schema
            ->statePath('data')
            ->components([
                Select::make('agency_id')
                    ->label('Agence de mes nouveaux documents')
                    ->options(self::choices($user))
                    ->required()
                    ->native()
                    ->helperText('Votre agence et celles où vous remplacez un collègue aujourd\'hui. '
                        .'Les nouvelles réservations et les nouveaux clients portent cette agence.'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Changer d\'agence')->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var User $user */
        $user = auth()->user();
        $agencyId = $this->form->getState()['agency_id'] ?? null;

        try {
            app(CurrentAgency::class)->switchTo($user, (int) $agencyId);
        } catch (AgencyAccessException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Agence active : '.self::choices($user)[(int) $agencyId])->send();

        $this->redirect(Dashboard::getUrl());
    }
}
