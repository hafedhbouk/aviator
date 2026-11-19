<?php

namespace App\Filament\Resources\Suppliers;

use App\Domain\Setup\Actions\SaveSupplier;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Supplier;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Fournisseurs (G-05) sur une page : super admin et admin seulement (SaveSupplier).
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Paramétrage';

    protected static ?int $navigationSort = 8;

    protected static ?string $modelLabel = 'fournisseur';

    protected static ?string $pluralModelLabel = 'fournisseurs';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'parametrage/fournisseurs';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')->label('Type')->required()->options(Supplier::TYPES),
            TextInput::make('name')->label('Nom')->required()->maxLength(255),
            TextInput::make('tax_id')->label('Matricule fiscal')->maxLength(32),
            TextInput::make('phone')->label('Téléphone')->tel()->maxLength(32),
            TextInput::make('email')->label('Adresse e-mail')->email()->maxLength(255),
            TextInput::make('address')->label('Adresse')->maxLength(255),
            Toggle::make('is_active')->label('Actif')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('type')->label('Type')->formatStateUsing(fn (string $state): string => Supplier::TYPES[$state] ?? $state),
                TextColumn::make('tax_id')->label('Matricule fiscal')->placeholder('—'),
                TextColumn::make('phone')->label('Téléphone')->placeholder('—'),
                IconColumn::make('is_active')->label('Actif')->boolean(),
            ])
            ->filters([SelectFilter::make('type')->label('Type')->options(Supplier::TYPES)])
            ->recordActions([
                EditAction::make()->using(fn (Supplier $record, array $data): Supplier => self::save($record, $data)),
            ])
            ->toolbarActions([]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(?Supplier $supplier, array $data): Supplier
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveSupplier::class)->handle($supplier, $data, $actor);
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw new Halt;
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageSuppliers::route('/')];
    }
}
