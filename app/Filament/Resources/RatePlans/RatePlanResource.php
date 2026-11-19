<?php

namespace App\Filament\Resources\RatePlans;

use App\Filament\Forms\AmountField;
use App\Filament\Resources\RatePlans\Pages\CreateRatePlan;
use App\Filament\Resources\RatePlans\Pages\EditRatePlan;
use App\Filament\Resources\RatePlans\Pages\ListRatePlans;
use App\Models\Agency;
use App\Models\RatePlan;
use App\Models\RatePlanLine;
use App\Models\Season;
use App\Models\VehicleCategory;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Grilles tarifaires (A-04) : par catégorie et saison, communes ou propres à une
 * agence (Q10), tranches de durée, prix journaliers HT (Q34). Règles dans SaveRatePlan.
 */
class RatePlanResource extends Resource
{
    protected static ?string $model = RatePlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Paramétrage';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'grille tarifaire';

    protected static ?string $pluralModelLabel = 'grilles tarifaires';

    protected static ?string $navigationLabel = 'Grilles tarifaires';

    protected static ?string $slug = 'parametrage/grilles';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Grille')
                ->columns(3)
                ->schema([
                    Select::make('vehicle_category_id')->label('Catégorie')->required()
                        ->options(fn (): array => self::categoryOptions()),
                    Select::make('season_id')->label('Saison')->required()
                        ->options(fn (): array => Season::query()->orderBy('starts_on')->get()
                            ->mapWithKeys(fn (Season $season): array => [$season->id => $season->label()])->all()),
                    Select::make('agency_id')->label('Agence')
                        ->options(fn (): array => self::agencyOptions())
                        ->placeholder('Grille commune (toutes les agences)')
                        ->helperText('Vide : grille commune. Une grille d\'agence prime sur la grille commune (Q10).'),
                    DatePicker::make('valid_from')->label('Valable à partir du')->required()
                        ->helperText('S\'applique aux jours loués à partir de cette date.'),
                    DatePicker::make('valid_to')->label('Jusqu\'au (inclus)')->afterOrEqual('valid_from')
                        ->helperText('Vide : sans fin. Pour changer un prix, fermez cette grille et créez-en une.'),
                    Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                ]),
            Section::make('Tranches de durée (prix journaliers HT)')
                ->description('Tranches continues à partir de 1 jour ; la dernière est sans limite. '
                    .'Le prix de la tranche s\'applique à tous les jours de la location (Q33).')
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('min_days')->label('De (jours)')->required()->integer()->minValue(1),
                            TextInput::make('max_days')->label('À (jours)')->integer()->minValue(1)->placeholder('Sans limite'),
                            AmountField::make('daily_amount')->label('Prix journalier HT')->required()->placeholder(null),
                        ])
                        ->columns(3)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->reorderable(false)
                        ->addActionLabel('Ajouter une tranche'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vehicleCategory.name')->label('Catégorie')->sortable(),
                TextColumn::make('season.name')->label('Saison')->sortable(),
                TextColumn::make('agency.name')->label('Agence')->placeholder('Commune'),
                TextColumn::make('valid_from')->label('Du')->date('d/m/Y'),
                TextColumn::make('valid_to')->label('Au')->date('d/m/Y')->placeholder('Sans fin'),
                TextColumn::make('lines')
                    ->label('Tranches')
                    ->state(fn (RatePlan $record): string => self::linesSummary($record))
                    ->wrap(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('vehicle_category_id')->label('Catégorie')->relationship('vehicleCategory', 'name'),
                SelectFilter::make('season_id')->label('Saison')->relationship('season', 'name'),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function linesSummary(RatePlan $plan): string
    {
        return $plan->lines->map(fn (RatePlanLine $line): string => ($line->max_days === null
            ? "{$line->min_days} j et plus"
            : "{$line->min_days} – {$line->max_days} j").' : '.$line->daily_amount->format())->implode(' ; ');
    }

    /**
     * @return array<int, string>
     */
    private static function categoryOptions(): array
    {
        /** @var array<int, string> */
        return VehicleCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
            ->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    private static function agencyOptions(): array
    {
        /** @var array<int, string> */
        return Agency::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRatePlans::route('/'),
            'create' => CreateRatePlan::route('/create'),
            'edit' => EditRatePlan::route('/{record}/edit'),
        ];
    }
}
