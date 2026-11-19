<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Setup\Actions\SaveCompanyProfile;
use App\Domain\Setup\Exceptions\SetupException;
use App\Models\Company;
use App\Models\User;
use App\Support\Files\PublicFiles;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Fiche société (A-01) : page unique. Les règles sont dans SaveCompanyProfile ;
 * la page ne fait que présenter le formulaire.
 *
 * @property-read Schema $form
 */
class CompanyProfile extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Paramétrage';

    protected static ?string $navigationLabel = 'Société';

    protected static ?string $title = 'Fiche société';

    protected static ?string $slug = 'parametrage/societe';

    protected static ?int $navigationSort = 1;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view', Company::class) ?? false;
    }

    public function mount(): void
    {
        $company = Company::current();

        $this->form->fill($company?->only([
            'legal_name', 'trade_name', 'tax_id', 'trade_register', 'address', 'city', 'phone', 'email',
            'logo_path', 'invoice_legal_mentions',
        ]) ?? []);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Identité')
                    ->columns(2)
                    ->schema([
                        TextInput::make('legal_name')->label('Raison sociale')->required()->maxLength(255),
                        TextInput::make('trade_name')->label('Nom commercial')->maxLength(255),
                        TextInput::make('tax_id')->label('Matricule fiscal')->maxLength(32)
                            ->helperText('Matricule commun aux 4 agences : pièce attendue (référentiel fiscal, §14, point 6).'),
                        TextInput::make('trade_register')->label('Identifiant RNE')->maxLength(64),
                        TextInput::make('currency')->label('Devise')
                            ->default(Company::CURRENCY)
                            ->formatStateUsing(fn (): string => Company::CURRENCY.' (dinar tunisien)')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Dinar tunisien uniquement (D26).'),
                    ]),
                Section::make('Coordonnées')
                    ->columns(2)
                    ->schema([
                        TextInput::make('address')->label('Adresse')->maxLength(255)->columnSpanFull(),
                        TextInput::make('city')->label('Ville')->maxLength(100),
                        TextInput::make('phone')->label('Téléphone')->tel()->maxLength(32),
                        TextInput::make('email')->label('Adresse e-mail')->email()->maxLength(255),
                    ]),
                Section::make('Logo')
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()
                            ->acceptedFileTypes(array_values(array_unique(PublicFiles::TYPES)))
                            ->maxSize(1024)
                            ->disk(PublicFiles::DISK)
                            ->directory(PublicFiles::COMPANY_DIRECTORY)
                            ->visibility('private')
                            // Aperçu par la route publique (D21) : le disque privé n'a pas d'URL.
                            ->getUploadedFileUsing(fn (string $file): ?array => self::uploadedLogo($file))
                            ->helperText('PNG, JPEG ou WebP, 1 Mo au plus. Affiché sur les documents.'),
                    ]),
                Section::make('Mentions des factures')
                    ->schema([
                        Textarea::make('invoice_legal_mentions')
                            ->label('Mentions des factures')
                            ->rows(4)
                            ->disabled(fn (): bool => ! self::canEditLegalMentions())
                            ->helperText('Saisies seulement à partir d\'une règle validée par le cabinet (règle FACTURE). '
                                .'Modifiables par le super admin ; lecture seule pour l\'admin.'),
                    ]),
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
                        Action::make('save')->label('Enregistrer')->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var User $actor */
        $actor = auth()->user();
        $data = $this->form->getState();

        try {
            app(SaveCompanyProfile::class)->handle($data, $actor);
        } catch (SetupException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Fiche société enregistrée')->send();
    }

    private static function canEditLegalMentions(): bool
    {
        return auth()->user()?->can('updateLegalMentions', Company::class) ?? false;
    }

    /**
     * @return array{name: string, size: int, type: string|null, url: string|null}|null
     */
    private static function uploadedLogo(string $file): ?array
    {
        if (! PublicFiles::hasAllowedShape($file)) {
            return null;
        }

        return [
            'name' => basename($file),
            'size' => 0,
            'type' => PublicFiles::contentType($file),
            'url' => PublicFiles::isListed($file) ? PublicFiles::url($file) : null,
        ];
    }
}
