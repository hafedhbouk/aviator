<?php

namespace App\Filament\Resources\Seasons;

use App\Domain\Setup\Actions\DeleteSeason;
use App\Domain\Setup\Actions\SaveSeason;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\Seasons\Pages\ManageSeasons;
use App\Models\Season;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Saisons datées (A-04), sur une seule page : création et modification en fenêtre,
 * par SaveSeason ; suppression d'une saison inutilisée, par DeleteSeason.
 */
class SeasonResource extends Resource
{
    protected static ?string $model = Season::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'Paramétrage';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'saison';

    protected static ?string $pluralModelLabel = 'saisons';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'parametrage/saisons';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nom')->required()->maxLength(100)->placeholder('Haute saison 2027'),
            DatePicker::make('starts_on')->label('Du (inclus)')->required(),
            DatePicker::make('ends_on')->label('Au (inclus)')->required()->afterOrEqual('starts_on'),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on')
            ->columns([
                TextColumn::make('name')->label('Nom'),
                TextColumn::make('starts_on')->label('Du')->date('d/m/Y')->sortable(),
                TextColumn::make('ends_on')->label('Au')->date('d/m/Y'),
                TextColumn::make('rate_plans_count')->label('Grilles')->counts('ratePlans'),
            ])
            ->recordActions([
                EditAction::make()->using(fn (Season $record, array $data): Season => self::save($record, $data)),
                DeleteAction::make()->using(function (Season $record): bool {
                    try {
                        app(DeleteSeason::class)->handle($record, self::actor());
                    } catch (SetupException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        throw new Halt;
                    }

                    return true;
                }),
            ])
            ->toolbarActions([]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(?Season $season, array $data): Season
    {
        try {
            return app(SaveSeason::class)->handle($season, $data, self::actor());
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw new Halt;
        }
    }

    private static function actor(): User
    {
        /** @var User */
        return auth()->user();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSeasons::route('/'),
        ];
    }
}
