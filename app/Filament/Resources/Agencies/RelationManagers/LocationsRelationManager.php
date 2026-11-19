<?php

namespace App\Filament\Resources\Agencies\RelationManagers;

use App\Domain\Setup\Actions\SaveAgencyLocation;
use App\Domain\Setup\Exceptions\SetupException;
use App\Domain\Setup\OpeningHours;
use App\Models\Agency;
use App\Models\AgencyLocation;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lieux de prise en charge et de restitution d'une agence, avec leurs horaires
 * (A-02). Écriture par SaveAgencyLocation ; aucune suppression (désactivation).
 * En lecture seule sur la fiche (ViewAgency), seule page ouverte à l'utilisateur d'agence.
 */
class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'locations';

    protected static ?string $title = 'Lieux et horaires';

    protected static ?string $modelLabel = 'lieu';

    protected static ?string $pluralModelLabel = 'lieux';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Lieu')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nom')->required()->maxLength(100)
                            ->placeholder('Agence, aéroport, hôtel…'),
                        TextInput::make('address')->label('Adresse')->maxLength(255),
                        Toggle::make('is_pickup')->label('Prise en charge')->default(true),
                        Toggle::make('is_return')->label('Restitution')->default(true),
                        Toggle::make('is_active')->label('Actif')->default(true),
                    ]),
                Section::make('Horaires d\'ouverture')
                    ->description('Heure de Tunis. Un jour sans plage est fermé ; aucune plage = horaires non renseignés. '
                        .'Journée coupée : deux plages.')
                    ->statePath('opening_hours')
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema(array_map(
                        fn (string $day, string $label): Repeater => Repeater::make($day)
                            ->label(ucfirst($label))
                            ->schema([
                                Grid::make(2)->schema([
                                    TimePicker::make('open')->label('Ouverture')->seconds(false)->required(),
                                    TimePicker::make('close')->label('Fermeture')->seconds(false)->required(),
                                ]),
                            ])
                            ->defaultItems(0)
                            ->maxItems(OpeningHours::MAX_RANGES_PER_DAY)
                            ->addActionLabel('Ajouter une plage')
                            ->reorderable(false),
                        array_keys(OpeningHours::DAYS),
                        OpeningHours::DAYS,
                    )),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom'),
                TextColumn::make('address')->label('Adresse')->placeholder('—'),
                IconColumn::make('is_pickup')->label('Prise en charge')->boolean(),
                IconColumn::make('is_return')->label('Restitution')->boolean(),
                TextColumn::make('opening_hours')
                    ->label('Horaires')
                    ->state(fn (AgencyLocation $record): string => $record->openingHours()->summary())
                    ->wrap(),
                IconColumn::make('is_active')->label('Actif')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Ajouter un lieu')
                    ->using(fn (array $data): AgencyLocation => $this->save(null, $data)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->using(fn (AgencyLocation $record, array $data): AgencyLocation => $this->save($record, $data)),
            ])
            ->toolbarActions([]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(?AgencyLocation $location, array $data): AgencyLocation
    {
        /** @var Agency $agency */
        $agency = $this->getOwnerRecord();
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveAgencyLocation::class)->handle($location, $agency, $data, $actor);
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw new Halt;
        }
    }
}
