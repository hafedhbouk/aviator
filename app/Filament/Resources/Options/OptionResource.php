<?php

namespace App\Filament\Resources\Options;

use App\Filament\Forms\AmountField;
use App\Filament\Resources\Options\Pages\CreateOption;
use App\Filament\Resources\Options\Pages\EditOption;
use App\Filament\Resources\Options\Pages\ListOptions;
use App\Filament\Resources\Options\Pages\ViewOption;
use App\Models\Option;
use App\Models\TaxCode;
use App\Support\Money\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Options facturables (A-05), liées à un code fiscal choisi (SaveOption). Écriture :
 * super admin et admin ; lecture : utilisateur d'agence.
 */
class OptionResource extends Resource
{
    protected static ?string $model = Option::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'Paramétrage';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'option';

    protected static ?string $pluralModelLabel = 'options';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'parametrage/options';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Option')->columns(2)->schema([
                TextInput::make('code')->label('Code')->required()->maxLength(32)->regex('/^[A-Za-z0-9_-]{2,32}$/'),
                TextInput::make('name')->label('Nom')->required()->maxLength(100),
                Select::make('pricing_unit')->label('Prix')->required()->options(Option::PRICING_UNITS)->default(Option::PER_DAY),
                AmountField::make('amount')->label('Prix HT'),
                AmountField::make('max_amount_per_rental')->label('Plafond par location (HT)')->placeholder('Sans plafond'),
                Select::make('tax_code_id')->label('Code fiscal')
                    ->options(fn (): array => TaxCode::query()->orderBy('id')->get()
                        ->mapWithKeys(fn (TaxCode $code): array => [$code->id => "{$code->code} — {$code->label}"])->all())
                    ->helperText('Choisi selon la nature réelle de la prestation, jamais d\'après son nom. Sans code, l\'option ne peut pas être activée.'),
                Toggle::make('is_active')->label('Active')->default(false)
                    ->helperText('Activable avec un code fiscal applicable et un prix HT.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Code')->fontFamily('mono'),
                TextColumn::make('name')->label('Nom')->searchable(),
                TextColumn::make('pricing_unit')->label('Prix')->formatStateUsing(fn (string $state): string => Option::PRICING_UNITS[$state] ?? $state),
                TextColumn::make('amount')->label('Prix HT')->formatStateUsing(fn (?Money $state): string => $state?->format() ?? 'Non fixé')->placeholder('Non fixé'),
                TextColumn::make('taxCode.code')->label('Code fiscal')->placeholder('Aucun')->fontFamily('mono'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOptions::route('/'),
            'create' => CreateOption::route('/create'),
            'view' => ViewOption::route('/{record}'),
            'edit' => EditOption::route('/{record}/edit'),
        ];
    }
}
