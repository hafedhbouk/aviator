<?php

namespace App\Filament\Resources\Vehicles\RelationManagers;

use App\Domain\Fleet\Actions\SaveVehicleDocument;
use App\Domain\Fleet\Exceptions\FleetException;
use App\Enums\VehicleDocumentType;
use App\Filament\Forms\AmountField;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\Files\PrivateFiles;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Documents datés du véhicule (B-05) : assurance, visite technique, vignette, carte
 * grise. Une nouvelle échéance = un nouveau document ; aucune suppression.
 * Écriture par SaveVehicleDocument (fleet.manage) ; lecture comme le véhicule.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    protected static ?string $modelLabel = 'document';

    protected static ?string $pluralModelLabel = 'documents';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('type')->label('Type')->required()
                    ->options(collect(VehicleDocumentType::cases())->mapWithKeys(fn (VehicleDocumentType $t): array => [$t->value => $t->label()])->all()),
                Select::make('supplier_id')->label('Compagnie ou fournisseur')
                    ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TextInput::make('reference')->label('Référence (police, procès-verbal…)')->maxLength(64),
                AmountField::make('amount')->label('Montant'),
                DatePicker::make('valid_from')->label('Début de validité'),
                DatePicker::make('valid_until')->label('Échéance')
                    ->helperText('Obligatoire, sauf pour la carte grise.'),
                // Dépôt sur le disque privé (D21), rattaché au document par l'action de domaine.
                FileUpload::make('file')->label('Pièce jointe (carte grise, attestation…)')
                    ->disk(PrivateFiles::DISK)
                    ->directory(PrivateFiles::UPLOAD_DIRECTORY)
                    ->visibility('private')
                    ->acceptedFileTypes(array_keys(PrivateFiles::TYPES))
                    ->maxSize(intdiv(PrivateFiles::MAX_BYTES, 1024))
                    ->previewable(false)
                    ->downloadable(false)
                    ->openable(false)
                    ->columnSpanFull()
                    ->helperText('PDF, JPEG, PNG ou WebP, 10 Mo au plus. Une pièce enregistrée ne se supprime pas.'),
            ]);
    }

    public function table(Table $table): Table
    {
        $today = CarbonImmutable::today();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('supplier'))
            ->defaultSort('valid_until', 'desc')
            ->columns([
                TextColumn::make('type')->label('Type')
                    ->formatStateUsing(fn (VehicleDocumentType $state): string => $state->label()),
                TextColumn::make('supplier.name')->label('Compagnie ou fournisseur')->placeholder('—'),
                TextColumn::make('reference')->label('Référence')->placeholder('—'),
                TextColumn::make('valid_from')->label('Début')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('valid_until')->label('Échéance')->date('d/m/Y')->placeholder('Sans échéance')
                    ->color(fn (VehicleDocument $record): ?string => $record->valid_until !== null && $record->valid_until->lessThan($today) ? 'danger' : null)
                    ->description(fn (VehicleDocument $record): ?string => $record->valid_until !== null && $record->valid_until->lessThan($today) ? 'Expiré' : null),
                TextColumn::make('amount')->label('Montant')
                    ->formatStateUsing(fn (?Money $state): string => $state?->format() ?? '—')->placeholder('—'),
                TextColumn::make('file_path')->label('Pièce jointe')
                    ->state(fn (VehicleDocument $record): ?string => $record->file_path !== null ? 'Ouvrir' : null)
                    ->url(fn (VehicleDocument $record): ?string => $record->file_path !== null
                        ? route('filament.admin.private-files.vehicle-document', ['document' => $record->id])
                        : null, shouldOpenInNewTab: true)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('type')->label('Type')
                    ->options(collect(VehicleDocumentType::cases())->mapWithKeys(fn (VehicleDocumentType $t): array => [$t->value => $t->label()])->all()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Ajouter un document')
                    ->using(fn (array $data): VehicleDocument => $this->save(null, $data)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->using(fn (VehicleDocument $record, array $data): VehicleDocument => $this->save($record, $data)),
            ])
            ->toolbarActions([]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(?VehicleDocument $document, array $data): VehicleDocument
    {
        /** @var Vehicle $vehicle */
        $vehicle = $this->getOwnerRecord();
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(SaveVehicleDocument::class)->handle($vehicle, $document, $data, $actor);
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw new Halt;
        }
    }
}
