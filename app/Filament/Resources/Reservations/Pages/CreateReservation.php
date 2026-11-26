<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Domain\Approvals\Actions\RequestApproval;
use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Availability\Exceptions\InvalidPeriod;
use App\Domain\Availability\Period;
use App\Domain\Crm\Actions\CreateCounterCustomer;
use App\Domain\Crm\CustomerSearch;
use App\Domain\Pricing\Exceptions\QuoteUnavailable;
use App\Domain\Pricing\QuoteCalculator;
use App\Domain\Rental\Actions\OpenContract;
use App\Enums\ReservationStatus;
use App\Filament\Forms\AmountField;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\Reservations\ReservationScreens;
use App\Models\Agency;
use App\Models\Contract;
use App\Models\Option;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Agency\CurrentAgency;
use App\Support\Money\AmountInput;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Throwable;

/**
 * Assistant « Nouvelle réservation » du comptoir (parcours P1) : client, période,
 * véhicule, options et devis, validation. L'écran ne calcule ni ne décide rien
 * (RG-18) : recherche D24 (CustomerSearch), création D40 (CreateCounterCustomer),
 * disponibilité (AvailabilityQuery), devis (QuoteCalculator) et enregistrement sous
 * verrou (SaveReservation) sont ceux du domaine.
 */
class CreateReservation extends CreateRecord
{
    use HasWizard;

    protected static string $resource = ReservationResource::class;

    /** Choix « Louer maintenant » de l'étape Validation : contrat au comptoir, sans réservation. */
    public const RENT_NOW = 'rent_now';

    protected function getRedirectUrl(): string
    {
        return $this->record instanceof Contract
            ? ContractResource::getUrl('view', ['record' => $this->record])
            : ReservationResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->record instanceof Contract ? "Contrat {$this->record->number} créé." : 'Réservation enregistrée.';
    }

    /**
     * Résultats de la dernière recherche de client (D24 : 10 au plus, sans coordonnée).
     *
     * @var list<array{customer_id: int, name: string, blacklisted: bool, watched: bool, rentals: int}>
     */
    public array $customerResults = [];

    /**
     * @return list<Step>
     */
    public function getSteps(): array
    {
        return [
            Step::make('Client')->schema([
                Grid::make(3)->schema([
                    Select::make('search_criterion')->label('Rechercher par')->dehydrated(false)
                        ->options(['id_document' => 'Pièce d\'identité', 'license' => 'Permis', 'phone' => 'Téléphone', 'name' => 'Nom complet'])
                        ->default('id_document'),
                    TextInput::make('search_value')->label('Valeur exacte')->dehydrated(false)->columnSpan(2)
                        ->helperText('Nom : prénom et nom complets. Aucune recherche partielle ; chaque recherche est journalisée.'),
                ]),
                Actions::make([
                    Action::make('searchCustomer')->label('Rechercher')->icon('heroicon-o-magnifying-glass')
                        ->action(fn (Get $get, Set $set) => $this->searchCustomers($get, $set)),
                    Action::make('newCustomer')->label('Nouveau client')->icon('heroicon-o-user-plus')->color('gray')
                        ->schema([
                            Radio::make('type')->label('Type')->options(['individual' => 'Particulier', 'company' => 'Entreprise'])->default('individual')->inline()->live(),
                            Grid::make(2)->schema([
                                TextInput::make('first_name')->label('Prénom')->visible(fn (Get $get): bool => $get('type') !== 'company'),
                                TextInput::make('last_name')->label('Nom')->visible(fn (Get $get): bool => $get('type') !== 'company'),
                                TextInput::make('company_name')->label('Raison sociale')->visible(fn (Get $get): bool => $get('type') === 'company'),
                                TextInput::make('phone')->label('Téléphone')->tel(),
                                Select::make('id_document_type')->label('Type de pièce')
                                    ->options(['cin' => 'CIN', 'passport' => 'Passeport', 'residence_permit' => 'Titre de séjour'])->default('cin'),
                                TextInput::make('id_document_number')->label('Numéro de pièce'),
                                TextInput::make('license_number')->label('Numéro de permis'),
                            ]),
                        ])
                        ->modalDescription('Pièce d\'identité ou permis obligatoire (D40). Un document déjà connu renvoie à la fiche existante.')
                        ->action(fn (array $data, Set $set) => $this->createCustomer($data, $set)),
                ]),
                Select::make('customer_id')->label('Client')->required()->live()
                    ->options(fn (): array => collect($this->customerResults)->mapWithKeys(fn (array $c): array => [
                        $c['customer_id'] => $c['name'].($c['blacklisted'] ? ' — dérogation du super admin requise' : ($c['watched'] ? ' — vigilance' : '')),
                    ])->all())
                    ->placeholder('Recherchez ou créez le client'),
                Textarea::make('override_reason')->label('Motif de la demande de dérogation (obligatoire)')
                    ->visible(fn (Get $get): bool => $this->isBlacklisted($get('customer_id')))
                    ->required(fn (Get $get): bool => $this->isBlacklisted($get('customer_id')))
                    ->minLength(RequestApproval::MIN_REASON_LENGTH)->maxLength(1000)
                    ->helperText('Client sur liste noire : la réservation est enregistrée en brouillon et la demande part au super admin. Elle ne pourra être confirmée qu\'après son approbation.'),
            ]),
            Step::make('Période')->columns(2)->schema([
                Select::make('agency_id')->label('Agence de départ et de retour')->required()->live()
                    ->options(fn (): array => $this->agencyOptions())
                    ->default(fn (): ?int => $this->defaultAgency()),
                Select::make('vehicle_category_id')->label('Catégorie')->required()->live()
                    ->options(fn (): array => VehicleCategory::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all()),
                DateTimePicker::make('starts_at')->label('Départ')->seconds(false)->required()->live(onBlur: true),
                DateTimePicker::make('ends_at')->label('Retour')->seconds(false)->required()->after('starts_at')->live(onBlur: true),
            ]),
            Step::make('Véhicule')->schema([
                Select::make('vehicle_id')->label('Véhicule')
                    ->placeholder('Sans véhicule précis : affectation au plus tard au départ')
                    ->options(fn (Get $get): array => ReservationScreens::vehicleOptions($get('agency_id'), $get('vehicle_category_id'), $get('starts_at'), $get('ends_at')))
                    ->disableOptionWhen(fn (string $label): bool => str_contains($label, 'non valide'))
                    ->helperText('Véhicules localisés dans l\'agence de départ et libres sur la période, délai de préparation compris.'),
            ]),
            Step::make('Options et devis')->schema([
                Repeater::make('option_lines')->label('Options')->defaultItems(0)->addActionLabel('Ajouter une option')->columns(2)->live()
                    ->schema([
                        Select::make('option_id')->label('Option')->required()
                            ->options(fn (): array => Option::query()->where('is_active', true)->whereNotNull('amount')->orderBy('name')->pluck('name', 'id')->all()),
                        TextInput::make('quantity')->label('Quantité')->integer()->minValue(1)->maxValue(10)->default(1)->required(),
                    ]),
                AmountField::make('discount_amount')->label('Remise HT')->live(onBlur: true)
                    ->helperText('Au-delà du seuil fixé par le super admin, la remise lui est soumise : la réservation est enregistrée, le départ attend son approbation.'),
                Textarea::make('discount_reason')->label('Motif de la remise (obligatoire au-delà du seuil)')
                    ->visible(fn (Get $get): bool => $this->discountNeedsApproval($get))
                    ->required(fn (Get $get): bool => $this->discountNeedsApproval($get))
                    ->minLength(RequestApproval::MIN_REASON_LENGTH)->maxLength(1000),
                TextEntry::make('quote_preview')->label('Devis')->state(fn (Get $get): string => $this->quotePreview($get)),
            ]),
            Step::make('Validation')->schema([
                Radio::make('status')->label('Enregistrer comme')->required()->default(ReservationStatus::Confirmed->value)
                    ->options(fn (Get $get): array => $this->isBlacklisted($get('customer_id'))
                        ? [ReservationStatus::Draft->value => 'Brouillon, en attente de la dérogation du super admin']
                        : [ReservationStatus::Confirmed->value => 'Réservation confirmée', ReservationStatus::Draft->value => 'Brouillon (devis, sans véhicule retenu)']
                            + (is_numeric($get('vehicle_id')) ? [self::RENT_NOW => 'Louer maintenant : contrat immédiat, sans réservation'] : [])),
                TextEntry::make('final_quote')->label('Devis')->state(fn (Get $get): string => $this->quotePreview($get)),
            ]),
        ];
    }

    /**
     * Préremplissage depuis le planning (parcours P2, étape 4) : véhicule et date de la
     * case cliquée. Seul un véhicule visible depuis le planning est repris ; tout est
     * contrôlé de nouveau par le domaine à l'enregistrement.
     */
    protected function afterFill(): void
    {
        $vehicleId = request()->query('vehicle');
        $starts = request()->query('starts');
        $state = [];

        if (is_numeric($vehicleId)) {
            $vehicle = Vehicle::query()->withoutGlobalScopes()->find((int) $vehicleId);

            if ($vehicle !== null && array_key_exists($vehicle->current_agency_id, $this->agencyOptions())) {
                $state += [
                    'vehicle_id' => $vehicle->id,
                    'agency_id' => $vehicle->current_agency_id,
                    'vehicle_category_id' => $vehicle->vehicle_category_id,
                ];
            }
        }

        if (is_string($starts) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $starts) === 1) {
            $state['starts_at'] = $starts.':00';
        }

        if ($state !== []) {
            $this->form->fill(array_merge($this->form->getRawState(), $state));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        $data['options'] = self::optionQuantities($data['option_lines'] ?? []);
        unset($data['option_lines']);

        // « Louer maintenant » (parcours P1, étape 6 bis) : contrat au comptoir (RG-03).
        if (($data['status'] ?? null) === self::RENT_NOW) {
            unset($data['status']);

            return ReservationScreens::run(fn () => app(OpenContract::class)->atCounter($data, $actor));
        }

        if ($this->isBlacklisted($data['customer_id'] ?? null)) {
            $data['status'] = ReservationStatus::Draft->value;
        }

        return ReservationScreens::run(fn () => app(SaveReservation::class)->handle(null, $data, $actor));
    }

    private function searchCustomers(Get $get, Set $set): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $this->customerResults = ReservationScreens::run(fn (): array => app(CustomerSearch::class)->search(
            $actor,
            (string) ($get('search_criterion') ?: 'id_document'),
            (string) $get('search_value'),
        ));

        $set('customer_id', count($this->customerResults) === 1 ? $this->customerResults[0]['customer_id'] : null);

        Notification::make()
            ->title($this->customerResults === [] ? 'Aucun client trouvé : vérifiez la saisie ou créez le client.' : count($this->customerResults).' client(s) trouvé(s).')
            ->status($this->customerResults === [] ? 'warning' : 'success')
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createCustomer(array $data, Set $set): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $result = ReservationScreens::run(fn (): array => app(CreateCounterCustomer::class)->handle($data, $actor));
        $customer = $result['customer'];

        $this->customerResults = [[
            'customer_id' => $customer->id,
            'name' => $customer->displayName(),
            'blacklisted' => $result['blacklisted'],
            'watched' => $result['watched'],
            'rentals' => 0,
        ]];

        $set('customer_id', $customer->id);

        if ($result['blacklisted']) {
            Notification::make()->danger()->title('Client déjà connu, sur liste noire : brouillon seulement, avec une demande de dérogation motivée au super admin.')->persistent()->send();

            return;
        }

        Notification::make()
            ->title($result['created'] ? 'Client créé.' : 'Client déjà connu : sa fiche existante est retenue.')
            ->body($result['phone_matches'] > 0 ? "Attention : ce téléphone figure déjà sur {$result['phone_matches']} autre(s) fiche(s)." : null)
            ->status($result['created'] ? 'success' : 'info')
            ->send();
    }

    private function quotePreview(Get $get): string
    {
        $agencyId = $get('agency_id');
        $categoryId = $get('vehicle_category_id');
        $startsAt = $get('starts_at');
        $endsAt = $get('ends_at');

        if (! is_numeric($agencyId) || ! is_numeric($categoryId) || ! is_string($startsAt) || ! is_string($endsAt) || $startsAt === '' || $endsAt === '') {
            return 'Choisissez l\'agence, la catégorie et la période.';
        }

        try {
            $quote = app(QuoteCalculator::class)->quote(
                (int) $categoryId,
                (int) $agencyId,
                Period::between($startsAt, $endsAt),
                self::optionQuantities((array) ($get('option_lines') ?? [])),
                AmountInput::parse($get('discount_amount')),
                allowApproval: true,
            );
        } catch (QuoteUnavailable|InvalidPeriod|InvalidArgumentException $exception) {
            return $exception->getMessage();
        } catch (Throwable) {
            return 'Devis indisponible : vérifiez la saisie.';
        }

        $text = "{$quote->billableDays()} jour(s) : location {$quote->rentalAmount->format()}, options {$quote->optionsAmount->format()}, "
            ."remise {$quote->discountAmount->format()}".($quote->discountRequiresApproval ? ' (soumise au super admin)' : '')
            .", total HT {$quote->totalExcludingTax->format()}.";

        return $quote->taxes === null
            ? "{$text} {$quote->taxNotice}"
            : "{$text} TTC {$quote->taxes->totalIncludingTax->format()}, net à payer (timbre compris) {$quote->taxes->netToPay->format()}.";
    }

    private function isBlacklisted(mixed $customerId): bool
    {
        return is_numeric($customerId)
            && (bool) (collect($this->customerResults)->firstWhere('customer_id', (int) $customerId)['blacklisted'] ?? false);
    }

    /**
     * Affichage seulement : le domaine refait le calcul et le contrôle à l'enregistrement.
     */
    private function discountNeedsApproval(Get $get): bool
    {
        try {
            $discount = AmountInput::parse($get('discount_amount'));
            $agencyId = $get('agency_id');
            $categoryId = $get('vehicle_category_id');

            if ($discount === null || $discount->isZero() || ! is_numeric($agencyId) || ! is_numeric($categoryId)) {
                return false;
            }

            return app(QuoteCalculator::class)->quote(
                (int) $categoryId,
                (int) $agencyId,
                Period::between((string) $get('starts_at'), (string) $get('ends_at')),
                self::optionQuantities((array) ($get('option_lines') ?? [])),
                $discount,
                allowApproval: true,
            )->discountRequiresApproval;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<mixed>  $lines
     * @return array<int, int>
     */
    private static function optionQuantities(array $lines): array
    {
        $options = [];

        foreach ($lines as $line) {
            if (is_array($line) && is_numeric($line['option_id'] ?? null) && is_numeric($line['quantity'] ?? null)) {
                $options[(int) $line['option_id']] = ($options[(int) $line['option_id']] ?? 0) + (int) $line['quantity'];
            }
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function agencyOptions(): array
    {
        /** @var User $actor */
        $actor = auth()->user();

        return Agency::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (Agency $agency): bool => $actor->canActOnAgency($agency->id))
            ->pluck('name', 'id')->all();
    }

    private function defaultAgency(): ?int
    {
        /** @var User $actor */
        $actor = auth()->user();

        return app(CurrentAgency::class)->id($actor) ?? $actor->agency_id;
    }
}
