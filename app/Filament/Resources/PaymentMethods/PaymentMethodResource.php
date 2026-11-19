<?php

namespace App\Filament\Resources\PaymentMethods;

use App\Domain\Setup\Actions\SavePaymentMethod;
use App\Domain\Setup\Exceptions\SetupException;
use App\Filament\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Models\PaymentMethod;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Modes de paiement (A-07) sur une page : écriture super admin et admin (SavePaymentMethod),
 * lecture pour l'agence.
 */
class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Paramétrage';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'mode de paiement';

    protected static ?string $pluralModelLabel = 'modes de paiement';

    protected static ?string $slug = 'parametrage/modes-de-paiement';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label('Code')->required()->maxLength(32)->regex('/^[a-z0-9_]{2,32}$/')
                ->disabledOn('edit')->dehydrated(fn (string $operation): bool => $operation === 'create')
                ->helperText('Minuscules, chiffres, « _ » ; fixé à la création.'),
            TextInput::make('name')->label('Nom')->required()->maxLength(100),
            Toggle::make('requires_reference')->label('Référence exigée à l\'encaissement'),
            Toggle::make('is_active')->label('Actif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Code')->fontFamily('mono'),
                TextColumn::make('name')->label('Nom'),
                IconColumn::make('requires_reference')->label('Référence exigée')->boolean(),
                IconColumn::make('is_active')->label('Actif')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->using(fn (PaymentMethod $record, array $data): PaymentMethod => self::save($record, $data)),
            ])
            ->toolbarActions([]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(?PaymentMethod $method, array $data): PaymentMethod
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SavePaymentMethod::class)->handle($method, $data, $actor);
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw new Halt;
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentMethods::route('/')];
    }
}
