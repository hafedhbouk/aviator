<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Domain\Administration\Actions\AssignReplacementAgency;
use App\Domain\Administration\Actions\RevokeReplacementAgency;
use App\Domain\Administration\Exceptions\AgencyAssignmentException;
use App\Models\Agency;
use App\Models\AgencyAssignment;
use App\Models\User;
use App\Support\Authorization\Role;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Affectations de remplacement d'un utilisateur d'agence (Q69) : création et
 * révocation par le super admin, toujours via les actions de domaine (journalisées).
 * Aucune modification ni suppression directe : l'historique est conservé.
 */
class AgencyAssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'agencyAssignments';

    protected static ?string $title = 'Affectations de remplacement';

    protected static ?string $modelLabel = 'affectation';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('agency_id')
                    ->label('Agence')
                    ->options(fn (): array => $this->agencyOptions())
                    ->required(),
                DatePicker::make('starts_on')
                    ->label('Du (inclus)')
                    ->required()
                    ->default(fn (): string => AgencyAssignment::today()),
                DatePicker::make('ends_on')
                    ->label('Au (inclus)')
                    ->required()
                    ->afterOrEqual('starts_on'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('agency.name')->label('Agence'),
                TextColumn::make('starts_on')->label('Du')->date('d/m/Y'),
                TextColumn::make('ends_on')->label('Au')->date('d/m/Y'),
                TextColumn::make('status')
                    ->label('État')
                    ->state(fn (AgencyAssignment $record): string => match ($record->status()) {
                        'upcoming' => 'À venir',
                        'active' => 'En cours',
                        default => 'Terminée',
                    })
                    ->badge(),
                TextColumn::make('creator.name')->label('Créée par')->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Affecter en remplacement')
                    ->visible(fn (): bool => $this->owner()->hasProfile(Role::AgencyUser))
                    ->using(function (array $data): AgencyAssignment {
                        try {
                            return app(AssignReplacementAgency::class)->handle(
                                $this->actor(),
                                $this->owner(),
                                (int) $data['agency_id'],
                                (string) $data['starts_on'],
                                (string) $data['ends_on'],
                            );
                        } catch (AgencyAssignmentException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            throw new Halt;
                        }
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Mettre fin')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('L\'accès à cette agence cesse immédiatement.')
                    ->visible(fn (AgencyAssignment $record): bool => $record->status() !== 'ended')
                    ->action(function (AgencyAssignment $record): void {
                        app(RevokeReplacementAgency::class)->handle($this->actor(), $record);
                        Notification::make()->success()->title('Affectation terminée.')->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    private function owner(): User
    {
        /** @var User */
        return $this->getOwnerRecord();
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }

    /**
     * @return array<int, string>
     */
    private function agencyOptions(): array
    {
        /** @var array<int, string> */
        return Agency::query()
            ->where('is_active', true)
            ->whereKeyNot($this->owner()->agency_id ?? 0)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
