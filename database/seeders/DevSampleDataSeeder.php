<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Administration\Actions\AssignReplacementAgency;
use App\Domain\Administration\Actions\SaveUser;
use App\Domain\Availability\Actions\CloseReservation;
use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Crm\Actions\AddRecommendation;
use App\Domain\Crm\Actions\CreateCounterCustomer;
use App\Domain\Fleet\Actions\RecordMileage;
use App\Domain\Fleet\Actions\SaveVehicle;
use App\Domain\Fleet\Actions\SaveVehicleDocument;
use App\Domain\Fleet\Financing\Actions\MarkInstallmentPaid;
use App\Domain\Fleet\Financing\Actions\SaveFinancing;
use App\Domain\Fleet\VehicleStatusService;
use App\Domain\Setup\Actions\SaveAgency;
use App\Domain\Setup\Actions\SaveAgencyLocation;
use App\Domain\Setup\Actions\SaveOption;
use App\Domain\Setup\Actions\SaveRatePlan;
use App\Domain\Setup\Actions\SaveSeason;
use App\Domain\Setup\Actions\SaveSupplier;
use App\Domain\Setup\Actions\SaveVehicleCategory;
use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\AgencyLocation;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\FinancingInstallment;
use App\Models\Option;
use App\Models\Reservation;
use App\Models\Season;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Jeu de données FICTIF et réaliste pour tester l'application sur le poste de
 * développement : paramétrage (lieux, catégories, saisons, grilles, options,
 * fournisseurs), utilisateurs, parc, documents, financements, clients et
 * conducteurs, recommandations, réservations.
 *
 *     php artisan db:seed --class=DevSampleDataSeeder
 *
 * - **Poste local uniquement** (APP_ENV=local) : refusé ailleurs, recette comprise
 *   (la recette a son propre jeu, DemoFleetSeeder). Jamais appelé par DatabaseSeeder.
 * - Prérequis : `php artisan db:seed` (agences, référentiel fiscal, options, modes de
 *   paiement) et un super admin (`php artisan aviator:create-super-admin`).
 * - Refusé si la base contient déjà des véhicules ou des clients : pour recommencer,
 *   `php artisan migrate:fresh --seed`, recréer le super admin, puis relancer.
 * - Tout passe par les actions du domaine (contrôles, numéros, verrous, journal).
 *   Seuls les champs de la fiche client et du permis que la phase 4 ne saisit pas
 *   encore (fiche complète en phase 7) sont complétés directement sur les modèles.
 * - Personnes, sociétés, fournisseurs, numéros de pièce, de permis et
 *   d'immatriculation sont inventés. La fiche société et le référentiel fiscal ne
 *   sont jamais modifiés.
 * - Mot de passe des comptes créés : tiré au hasard et affiché une seule fois à la
 *   fin (aucun secret dans le code).
 */
class DevSampleDataSeeder extends Seeder
{
    private User $actor;

    private CarbonImmutable $today;

    /** @var array<string, Agency> */
    private array $agencies = [];

    /** @var array<string, VehicleCategory> */
    private array $categories = [];

    /** @var array<string, list<Vehicle>> */
    private array $fleet = [];

    /** @var array<string, list<Customer>> */
    private array $customers = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $warnings = [];

    private const WEEK_HOURS = [
        'monday' => [['open' => '08:00', 'close' => '18:00']],
        'tuesday' => [['open' => '08:00', 'close' => '18:00']],
        'wednesday' => [['open' => '08:00', 'close' => '18:00']],
        'thursday' => [['open' => '08:00', 'close' => '18:00']],
        'friday' => [['open' => '08:00', 'close' => '18:00']],
        'saturday' => [['open' => '08:30', 'close' => '14:00']],
        'sunday' => [],
    ];

    private const AIRPORT_HOURS = [
        'monday' => [['open' => '06:00', 'close' => '23:00']],
        'tuesday' => [['open' => '06:00', 'close' => '23:00']],
        'wednesday' => [['open' => '06:00', 'close' => '23:00']],
        'thursday' => [['open' => '06:00', 'close' => '23:00']],
        'friday' => [['open' => '06:00', 'close' => '23:00']],
        'saturday' => [['open' => '06:00', 'close' => '23:00']],
        'sunday' => [['open' => '07:00', 'close' => '22:00']],
    ];

    /** Agences : adresse, téléphone, lieux (nom, adresse, aéroport ?). */
    private const AGENCIES = [
        'DJE' => ['Avenue Habib Bourguiba, Houmt Souk 4180', '75 650 100', [['Aéroport Djerba-Zarzis', 'Hall des arrivées, Mellita 4120', true], ['Zone touristique Midoun', 'Route touristique, Midoun 4116', false]]],
        'SFX' => ['Route de Tunis km 2, Sfax 3027', '74 410 200', [['Agence centre-ville Sfax', 'Rue Habib Maazoun, Sfax 3000', false], ['Aéroport Sfax-Thyna', 'Route de l\'aéroport, El Ain 3062', true]]],
        'SOU' => ['Boulevard du 14 Janvier, Sousse 4000', '73 225 300', [['Agence Sousse Khezama', 'Avenue Taïeb Mhiri, Khezama 4051', false], ['Aéroport Monastir Habib-Bourguiba', 'Skanes, Monastir 5065', true]]],
        'TUN' => ['Rue du Lac Biwa, Les Berges du Lac 1053', '71 960 400', [['Agence Les Berges du Lac', 'Rue du Lac Biwa, Tunis 1053', false], ['Aéroport Tunis-Carthage', 'Hall des arrivées, Tunis 2035', true]]],
    ];

    /**
     * Catégories : nom, caution, âge, ancienneté du permis, km inclus par jour,
     * prix du km supplémentaire, franchise, délai de préparation (min),
     * tarifs journaliers [basse, moyenne, haute, fêtes] pour 1 à 2 jours.
     */
    private const CATEGORIES = [
        'ECO' => ['Économique', '500,000', 21, 2, null, null, '1 000,000', 60, [65, 80, 110, 120]],
        'CIT' => ['Citadine', '700,000', 21, 2, null, null, '1 500,000', 60, [80, 95, 130, 140]],
        'BER' => ['Berline', '900,000', 23, 3, null, null, '2 000,000', 90, [95, 115, 150, 160]],
        'SUV' => ['SUV compact', '1 200,000', 23, 3, 300, '0,250', '2 500,000', 90, [140, 165, 220, 240]],
        'PRE' => ['Premium', '2 000,000', 25, 5, 250, '0,400', '4 000,000', 120, [220, 260, 330, 360]],
        'VAN' => ['Monospace 8-9 places', '1 500,000', 25, 3, 300, '0,300', '3 000,000', 120, [180, 210, 270, 290]],
    ];

    /** Modèles par catégorie : marque, modèle, carburant, boîte, couleurs. */
    private const MODELS = [
        'ECO' => [['Kia', 'Picanto', 'petrol', 'manual'], ['Hyundai', 'Grand i10', 'petrol', 'manual'], ['Fiat', 'Panda', 'petrol', 'manual']],
        'CIT' => [['Peugeot', '208', 'petrol', 'manual'], ['Renault', 'Clio 5', 'petrol', 'manual'], ['Volkswagen', 'Polo', 'petrol', 'automatic'], ['Seat', 'Ibiza', 'petrol', 'manual']],
        'BER' => [['Peugeot', '301', 'diesel', 'manual'], ['Hyundai', 'Accent', 'petrol', 'automatic'], ['Dacia', 'Logan', 'diesel', 'manual']],
        'SUV' => [['Hyundai', 'Tucson', 'diesel', 'automatic'], ['Kia', 'Sportage', 'hybrid', 'automatic'], ['Peugeot', '2008', 'petrol', 'automatic'], ['Dacia', 'Duster', 'diesel', 'manual']],
        'PRE' => [['Mercedes-Benz', 'Classe C', 'diesel', 'automatic'], ['Skoda', 'Superb', 'diesel', 'automatic']],
        'VAN' => [['Hyundai', 'H-1', 'diesel', 'manual'], ['Renault', 'Trafic', 'diesel', 'manual']],
    ];

    private const COLORS = ['Blanc', 'Gris métallisé', 'Noir', 'Bleu nuit', 'Rouge', 'Argent', 'Beige'];

    /** Répartition du parc par agence. */
    private const FLEET_MIX = [
        'DJE' => ['ECO' => 3, 'CIT' => 4, 'BER' => 2, 'SUV' => 2, 'PRE' => 1, 'VAN' => 1],
        'SFX' => ['ECO' => 2, 'CIT' => 2, 'BER' => 2, 'SUV' => 1, 'VAN' => 1],
        'SOU' => ['ECO' => 2, 'CIT' => 3, 'BER' => 1, 'SUV' => 2, 'PRE' => 1],
        'TUN' => ['ECO' => 2, 'CIT' => 3, 'BER' => 2, 'SUV' => 2, 'PRE' => 1, 'VAN' => 1],
    ];

    private const FIRST_NAMES_M = ['Mohamed', 'Ahmed', 'Ali', 'Youssef', 'Karim', 'Sami', 'Hichem', 'Walid', 'Mehdi', 'Anis', 'Bilel', 'Nabil', 'Riadh', 'Slim', 'Fares', 'Amine', 'Skander', 'Hamza', 'Oussama', 'Lotfi'];

    private const FIRST_NAMES_F = ['Fatma', 'Amira', 'Salma', 'Ines', 'Rim', 'Nour', 'Mariem', 'Sana', 'Olfa', 'Leila', 'Asma', 'Hela', 'Yasmine', 'Emna', 'Sonia', 'Khadija'];

    private const LAST_NAMES = ['Ben Salah', 'Trabelsi', 'Gharbi', 'Hammami', 'Jaziri', 'Mejri', 'Ayari', 'Bouazizi', 'Chaabane', 'Dridi', 'Ferchichi', 'Haddad', 'Jebali', 'Karoui', 'Mansouri', 'Nasri', 'Ouertani', 'Riahi', 'Sassi', 'Tlili', 'Zouari', 'Ben Ammar', 'Belhadj', 'Chebbi', 'Kchaou', 'Masmoudi', 'Ellouze', 'Feki', 'Sellami', 'Abid'];

    /** Clients étrangers (tourisme) : prénom, nom, nationalité. */
    private const FOREIGNERS = [
        ['Julien', 'Moreau', 'FR'], ['Claire', 'Dubois', 'FR'], ['Thomas', 'Lefèvre', 'FR'], ['Nathalie', 'Garnier', 'FR'],
        ['Markus', 'Schneider', 'DE'], ['Anna', 'Weber', 'DE'], ['Marco', 'Rossi', 'IT'], ['Giulia', 'Bianchi', 'IT'],
        ['Karim', 'Benali', 'DZ'], ['Sofiane', 'Haddadi', 'DZ'], ['Abdelkader', 'Elmrabet', 'LY'], ['Pierre', 'Lambert', 'BE'],
    ];

    private const CITIES = ['DJE' => ['Djerba', 'Zarzis', 'Medenine', 'Gabès'], 'SFX' => ['Sfax', 'Sakiet Ezzit', 'Mahrès', 'Kerkennah'], 'SOU' => ['Sousse', 'Monastir', 'Hammam Sousse', 'Mahdia'], 'TUN' => ['Tunis', 'La Marsa', 'Ariana', 'Ben Arous']];

    private const STREETS = ['Rue de la Liberté', 'Avenue de la République', 'Rue Ibn Khaldoun', 'Avenue Farhat Hached', 'Rue de Marseille', 'Rue Mongi Slim', 'Avenue Hédi Chaker', 'Rue Ali Belhouane'];

    private const COMPANIES = [
        ['Sahel Ingénierie SARL', 'SOU'], ['Djerba Événements SUARL', 'DJE'], ['Atlas Distribution SA', 'TUN'], ['Sfax Logistique Services', 'SFX'],
        ['Médina Tours SARL', 'DJE'], ['Carthage Conseil SARL', 'TUN'], ['Olivia Agro Export', 'SFX'], ['Kantaoui Hôtellerie SA', 'SOU'],
        ['Lac Digital Solutions', 'TUN'], ['Sud Bâtiment et Travaux', 'DJE'],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Jeu de données de développement refusé hors du poste local (APP_ENV=local).');
        }

        if (Vehicle::query()->withoutGlobalScopes()->exists() || Customer::query()->withoutGlobalScopes()->exists()) {
            $this->command?->warn('La base contient déjà des véhicules ou des clients : rien n\'est fait. Pour recommencer : php artisan migrate:fresh --seed, puis aviator:create-super-admin.');

            return;
        }

        $this->actor = User::query()->whereHas('roles', fn ($query) => $query->where('name', Role::SuperAdmin->value))->first()
            ?? throw new RuntimeException('Aucun super admin : lancez d\'abord « php artisan aviator:create-super-admin ».');
        $this->today = CarbonImmutable::now((string) config('app.timezone'))->startOfDay();
        mt_srand(2026);

        foreach (Agency::query()->orderBy('id')->get() as $agency) {
            $this->agencies[$agency->code] = $agency;
        }

        if (array_diff(array_keys(self::AGENCIES), array_keys($this->agencies)) !== []) {
            throw new RuntimeException('Agences DJE, SFX, SOU et TUN attendues : lancez d\'abord « php artisan db:seed ».');
        }

        Auth::login($this->actor);

        try {
            // Tout ou rien : une erreur ne laisse pas de données partielles.
            $password = DB::transaction(fn (): ?string => $this->load());
        } finally {
            Auth::logout();
        }

        foreach ($this->counts as $label => $count) {
            $this->command?->line(sprintf('  %-28s %d', $label, $count));
        }

        foreach ($this->warnings as $warning) {
            $this->command?->warn('  '.$warning);
        }

        if (is_string($password)) {
            $this->command?->info('Mot de passe des comptes créés (affiché une seule fois) : '.$password);
        }
    }

    private function load(): ?string
    {
        $this->step('Agences et lieux', fn () => $this->agencies());
        $this->step('Catégories', fn () => $this->categories());
        $this->step('Saisons et grilles tarifaires', fn () => $this->seasonsAndRates());
        $this->step('Options', fn () => $this->options());
        $this->step('Fournisseurs', fn () => $this->suppliers());
        $password = $this->step('Utilisateurs', fn () => $this->users());
        $this->step('Parc et documents', fn () => $this->vehicles());
        $this->step('Leasings et crédits', fn () => $this->financings());
        $this->step('Clients et conducteurs', fn () => $this->customersAndDrivers());
        $this->step('Recommandations', fn () => $this->recommendations());
        $this->step('Réservations', fn () => $this->reservations());

        return is_string($password) ? $password : null;
    }

    private function step(string $label, callable $callback): mixed
    {
        $this->command?->info($label.'…');

        return $callback();
    }

    private function count(string $label, int $by = 1): void
    {
        $this->counts[$label] = ($this->counts[$label] ?? 0) + $by;
    }

    private function agencies(): void
    {
        foreach (self::AGENCIES as $code => [$address, $phone, $locations]) {
            $agency = $this->agencies[$code];

            if ($agency->address === null) {
                $this->agencies[$code] = app(SaveAgency::class)->handle($agency, [
                    'name' => $agency->name, 'city' => $agency->city, 'address' => $address,
                    'phone' => $phone, 'email' => mb_strtolower($agency->name).'@aviator.test', 'is_active' => true,
                ], $this->actor);
            }

            foreach ($locations as [$name, $locationAddress, $airport]) {
                if (AgencyLocation::query()->where('agency_id', $agency->id)->where('name', $name)->exists()) {
                    continue;
                }

                app(SaveAgencyLocation::class)->handle(null, $agency, [
                    'name' => $name, 'address' => $locationAddress, 'is_pickup' => true, 'is_return' => true,
                    'opening_hours' => $airport ? self::AIRPORT_HOURS : self::WEEK_HOURS, 'is_active' => true,
                ], $this->actor);
                $this->count('Lieux');
            }
        }
    }

    private function categories(): void
    {
        $order = 10;

        foreach (self::CATEGORIES as $code => [$name, $deposit, $age, $years, $km, $extraKm, $excess, $buffer]) {
            $this->categories[$code] = VehicleCategory::query()->where('code', $code)->first()
                ?? app(SaveVehicleCategory::class)->handle(null, [
                    'code' => $code, 'name' => $name, 'deposit_amount' => $deposit,
                    'min_driver_age' => $age, 'min_license_years' => $years,
                    'included_km_per_day' => $km, 'extra_km_amount' => $extraKm,
                    'insurance_excess_amount' => $excess, 'preparation_buffer_minutes' => $buffer,
                    'sort_order' => $order, 'is_active' => true,
                ], $this->actor);
            $order += 10;
            $this->count('Catégories');
        }
    }

    private function seasonsAndRates(): void
    {
        // Index du tarif : 0 basse, 1 moyenne, 2 haute, 3 fêtes de fin d'année.
        $seasons = [];

        foreach ([2026, 2027] as $year) {
            $seasons[] = ["Basse saison {$year}", "{$year}-01-06", "{$year}-03-31", 0];
            $seasons[] = ["Printemps {$year}", "{$year}-04-01", "{$year}-06-14", 1];
            $seasons[] = ["Haute saison {$year}", "{$year}-06-15", "{$year}-09-15", 2];
            $seasons[] = ["Arrière-saison {$year}", "{$year}-09-16", "{$year}-10-31", 1];
            $seasons[] = ["Hiver {$year}", "{$year}-11-01", "{$year}-12-19", 0];
            $seasons[] = ["Fêtes de fin d'année {$year}", "{$year}-12-20", ($year + 1).'-01-05', 3];
        }

        array_unshift($seasons, ['Début d\'année 2026', '2026-01-01', '2026-01-05', 3]);

        foreach ($seasons as [$name, $from, $to, $level]) {
            $season = app(SaveSeason::class)->handle(null, ['name' => $name, 'starts_on' => $from, 'ends_on' => $to], $this->actor);
            $this->count('Saisons');

            foreach (self::CATEGORIES as $code => $definition) {
                $base = $definition[8][$level];

                app(SaveRatePlan::class)->handle(null, [
                    'vehicle_category_id' => $this->categories[$code]->id, 'season_id' => $season->id, 'agency_id' => null,
                    'valid_from' => $from, 'valid_to' => $to, 'is_active' => true,
                    'lines' => [
                        ['min_days' => 1, 'max_days' => 2, 'daily_amount' => $this->amount($base)],
                        ['min_days' => 3, 'max_days' => 6, 'daily_amount' => $this->amount(intdiv($base * 92, 100))],
                        ['min_days' => 7, 'max_days' => 29, 'daily_amount' => $this->amount(intdiv($base * 85, 100))],
                        ['min_days' => 30, 'max_days' => null, 'daily_amount' => $this->amount(intdiv($base * 75, 100))],
                    ],
                ], $this->actor);
                $this->count('Grilles tarifaires');
            }
        }

        // Surcharge de Tunis en haute saison 2027 pour les citadines (grille par agence).
        $summer = Season::query()->where('name', 'Haute saison 2027')->firstOrFail();
        app(SaveRatePlan::class)->handle(null, [
            'vehicle_category_id' => $this->categories['CIT']->id, 'season_id' => $summer->id, 'agency_id' => $this->agencies['TUN']->id,
            'valid_from' => '2027-06-15', 'valid_to' => '2027-09-15', 'is_active' => true,
            'lines' => [['min_days' => 1, 'max_days' => null, 'daily_amount' => '125,000']],
        ], $this->actor);
        $this->count('Grilles tarifaires');
    }

    private function options(): void
    {
        $prices = ['EXTRA_DRIVER' => ['10,000', null], 'CHILD_SEAT' => ['8,000', '80,000'], 'GPS' => ['10,000', '100,000'], 'EXCESS_REDUCTION' => ['25,000', null]];

        foreach ($prices as $code => [$amount, $max]) {
            $option = Option::query()->where('code', $code)->first();

            if ($option === null || $option->is_active) {
                continue;
            }

            $data = [
                'code' => $option->code, 'name' => $option->name, 'pricing_unit' => $option->pricing_unit,
                'tax_code_id' => $option->tax_code_id, 'amount' => $amount, 'max_amount_per_rental' => $max,
            ];

            try {
                app(SaveOption::class)->handle($option, $data + ['is_active' => true], $this->actor);
                $this->count('Options activées');
            } catch (Throwable $exception) {
                // Règle fiscale non applicable (CONDITIONAL, par exemple) : prix saisi, option inactive.
                app(SaveOption::class)->handle($option, $data + ['is_active' => false], $this->actor);
                $this->warnings[] = "Option {$code} laissée inactive : {$exception->getMessage()}";
            }
        }
    }

    private function suppliers(): void
    {
        $suppliers = [
            ['insurer', 'Assurances Ennour (fictif)', '0712345A/M/000', '71 800 100'],
            ['insurer', 'Mutuelle Méditerranée Assurances (fictif)', '0723456B/A/M/000', '71 800 200'],
            ['leasing_company', 'Leasing du Sahel (fictif)', '0734567C/A/M/000', '73 300 300'],
            ['leasing_company', 'Atlas Leasing (fictif)', '0745678D/A/M/000', '71 900 400'],
            ['bank', 'Banque de l\'Atlas (fictif)', '0756789E/A/M/000', '71 100 500'],
            ['garage', 'Garage Ben Ammar Auto', '1234567F/A/M/000', '75 600 600'],
            ['garage', 'Sfax Pneus Service', '1245678G/A/M/000', '74 400 700'],
            ['other', 'Station de lavage Lac Clean', null, '71 960 800'],
        ];

        foreach ($suppliers as [$type, $name, $taxId, $phone]) {
            if (Supplier::query()->where('name', $name)->exists()) {
                continue;
            }

            app(SaveSupplier::class)->handle(null, [
                'type' => $type, 'name' => $name, 'tax_id' => $taxId, 'phone' => $phone,
                'email' => Str::slug(str_replace(' (fictif)', '', $name)).'@fournisseur.test', 'is_active' => true,
            ], $this->actor);
            $this->count('Fournisseurs');
        }
    }

    private function users(): ?string
    {
        $password = Str::password(16, symbols: false);
        $created = false;

        $users = [
            ['Samir Ben Youssef', 'admin.siege@aviator.test', Role::Admin, 'DJE'],
            ['Nour Hammami', 'agent.djerba@aviator.test', Role::AgencyUser, 'DJE'],
            ['Walid Kchaou', 'agent.sfax@aviator.test', Role::AgencyUser, 'SFX'],
            ['Ines Jaziri', 'agent.sousse@aviator.test', Role::AgencyUser, 'SOU'],
            ['Mehdi Riahi', 'agent.tunis@aviator.test', Role::AgencyUser, 'TUN'],
        ];

        foreach ($users as [$name, $email, $role, $agency]) {
            if (User::query()->where('email', $email)->exists()) {
                continue;
            }

            $user = app(SaveUser::class)->handle(null, [
                'name' => $name, 'email' => $email, 'role' => $role->value,
                'agency_id' => $this->agencies[$agency]->id, 'is_active' => true,
            ], $this->actor);
            $user->forceFill(['password' => Hash::make($password)])->save();
            $created = true;
            $this->count('Utilisateurs');
        }

        // Remplacement : l'agent de Sfax couvre Sousse la semaine prochaine.
        $sfax = User::query()->where('email', 'agent.sfax@aviator.test')->first();

        if ($sfax !== null && $created) {
            try {
                app(AssignReplacementAgency::class)->handle($this->actor, $sfax, $this->agencies['SOU']->id, $this->today->addWeek()->startOfWeek()->toDateString(), $this->today->addWeek()->startOfWeek()->addDays(4)->toDateString());
                $this->count('Remplacements');
            } catch (Throwable $exception) {
                $this->warnings[] = 'Remplacement non créé : '.$exception->getMessage();
            }
        }

        return $created ? $password : null;
    }

    private function vehicles(): void
    {
        $insurers = Supplier::query()->where('type', 'insurer')->pluck('id')->all();
        $number = 0;

        foreach (self::FLEET_MIX as $agencyCode => $mix) {
            foreach ($mix as $categoryCode => $quantity) {
                for ($i = 0; $i < $quantity; $i++) {
                    $number++;
                    [$brand, $model, $fuel, $gearbox] = self::MODELS[$categoryCode][($number + $i) % count(self::MODELS[$categoryCode])];
                    $year = 2021 + ($number % 5);
                    $age = max(0, 2026 - $year);
                    $mileage = 8000 + $age * 28000 + mt_rand(0, 9000);
                    $acquired = CarbonImmutable::create($year, 1 + ($number % 12), 1 + ($number % 27));
                    $acquired = $acquired->greaterThan($this->today) ? $this->today->subMonths(2) : $acquired;
                    $mode = match (true) {
                        in_array($number, [3, 11, 19, 27], true) => 'leasing',
                        in_array($number, [6, 22], true) => 'credit',
                        default => 'cash',
                    };

                    $vehicle = app(SaveVehicle::class)->handle(null, [
                        'internal_code' => sprintf('%s-%03d', $agencyCode, $number),
                        'plate_number' => sprintf('%d TU %04d', 225 + ($year - 2021) * 7 + ($number % 5), 1000 + $number * 137 % 9000),
                        'vin' => sprintf('VF%s%013d', strtoupper(substr(Str::ascii($brand), 0, 1)), 4_200_000_000 + $number * 7919),
                        'vehicle_category_id' => $this->categories[$categoryCode]->id,
                        'brand' => $brand, 'model' => $model, 'year' => (string) $year,
                        'color' => self::COLORS[$number % count(self::COLORS)],
                        'fuel' => $fuel, 'transmission' => $gearbox, 'agency_id' => $this->agencies[$agencyCode]->id,
                        'acquired_on' => $acquired->toDateString(),
                        'purchase_amount' => $this->amount(match ($categoryCode) {
                            'ECO' => 42000, 'CIT' => 58000, 'BER' => 66000, 'SUV' => 105000, 'PRE' => 175000, default => 120000
                        } + mt_rand(0, 6000)),
                        'financing_mode' => $mode, 'planned_holding_months' => (string) (in_array($categoryCode, ['PRE', 'SUV'], true) ? 48 : 36),
                        'initial_mileage' => (string) $mileage,
                    ], $this->actor);
                    $this->count('Véhicules');

                    // Assurance annuelle et visite technique ; cas particuliers pour les alertes.
                    $insuranceUntil = match ($number) {
                        5 => $this->today->subDays(3),      // expirée : le véhicule passe « Indisponible »
                        9 => $this->today->addDays(10),     // expire bientôt
                        default => $this->today->addDays(60 + ($number * 23) % 300),
                    };
                    $inspectionUntil = $number === 14 ? $this->today->addDays(20) : $this->today->addDays(120 + ($number * 31) % 240);

                    $this->document($vehicle, 'insurance', $insuranceUntil->subYear(), $insuranceUntil, $insurers[$number % count($insurers)], sprintf('POL-%d-%05d', $insuranceUntil->year, 10000 + $number));
                    $this->document($vehicle, 'technical_inspection', $inspectionUntil->subYear(), $inspectionUntil, null, sprintf('VT-%06d', 300000 + $number * 11));
                    $this->document($vehicle, 'road_tax', CarbonImmutable::create(2026, 3, 1), CarbonImmutable::create(2027, 3, 5), null, sprintf('VG-2026-%05d', 50000 + $number));
                    $this->document($vehicle, 'registration', $acquired, null, null, sprintf('CG-%08d', 12000000 + $number * 3));

                    // Relevé kilométrique récent (retour de location fictif).
                    app(RecordMileage::class)->handle($vehicle->refresh(), $mileage + 350 + mt_rand(0, 1200), $this->actor, reason: 'Relevé au retour');

                    $this->fleet[$agencyCode][] = $vehicle->refresh();
                }
            }
        }

        // Un véhicule en maintenance et un immobilisé (sinistre) dans deux agences.
        $status = app(VehicleStatusService::class);
        $status->transition($this->fleet['DJE'][2], VehicleStatus::Maintenance, actor: $this->actor, reason: 'Vidange et plaquettes de frein');
        $status->transition($this->fleet['TUN'][4], VehicleStatus::Maintenance, actor: $this->actor, reason: 'Révision des 60 000 km');
        $status->transition($this->fleet['SOU'][3], VehicleStatus::Unavailable, actor: $this->actor, reason: 'Sinistre : choc arrière, expertise en attente');
    }

    private function document(Vehicle $vehicle, string $type, CarbonImmutable $from, ?CarbonImmutable $until, ?int $supplierId, string $reference): void
    {
        app(SaveVehicleDocument::class)->handle($vehicle, null, array_filter([
            'type' => $type, 'supplier_id' => $supplierId, 'reference' => $reference,
            'valid_from' => $from->toDateString(), 'valid_until' => $until?->toDateString(),
        ], fn (mixed $value): bool => $value !== null), $this->actor);
        $this->count('Documents de véhicules');
    }

    private function financings(): void
    {
        $lessors = Supplier::query()->where('type', 'leasing_company')->pluck('id')->all();
        $bank = Supplier::query()->where('type', 'bank')->value('id');
        $i = 0;

        foreach (Vehicle::query()->withoutGlobalScopes()->whereIn('financing_mode', ['leasing', 'credit'])->orderBy('id')->get() as $vehicle) {
            $i++;
            $start = $this->today->subMonths(4 + $i * 5)->startOfMonth()->addDays(9);

            $data = $vehicle->financing_mode->value === 'leasing'
                ? [
                    'type' => 'leasing', 'supplier_id' => $lessors[$i % count($lessors)], 'reference' => sprintf('LS-%d-%04d', $start->year, 1200 + $i),
                    'starts_on' => $start->toDateString(), 'first_due_on' => $start->addMonth()->toDateString(), 'ends_on' => $start->addYears(4)->toDateString(),
                    'initial_payment_amount' => $this->amount(9000 + $i * 1500), 'monthly_amount_excl_tax' => $this->amount(1100 + $i * 90),
                    'monthly_amount_incl_tax' => $this->amount(intdiv((1100 + $i * 90) * 119, 100)), 'residual_value_amount' => $this->amount(6000 + $i * 500),
                    'purchase_option_on' => $start->addYears(4)->toDateString(),
                ]
                : [
                    'type' => 'credit', 'supplier_id' => $bank, 'reference' => sprintf('CR-%d-%05d', $start->year, 40000 + $i),
                    'starts_on' => $start->toDateString(), 'first_due_on' => $start->addMonth()->toDateString(),
                    'principal_amount' => $this->amount(60000 + $i * 5000), 'interest_rate' => '9,75', 'installments_count' => '60',
                ];

            $financing = app(SaveFinancing::class)->handle($vehicle, null, $data, $this->actor);
            $this->count('Financements');

            // Échéances passées réglées, sauf la dernière (retard de paiement à suivre).
            $due = FinancingInstallment::query()->where('vehicle_financing_id', $financing->id)->where('due_date', '<', $this->today->toDateString())->orderBy('due_date')->get();

            foreach ($due->slice(0, max(0, $due->count() - ($i === 1 ? 1 : 0))) as $installment) {
                app(MarkInstallmentPaid::class)->handle($installment, CarbonImmutable::parse($installment->due_date)->addDays(2)->min($this->today), $this->actor);
                $this->count('Échéances réglées');
            }
        }
    }

    private function customersAndDrivers(): void
    {
        $sequence = 0;

        foreach (array_keys(self::AGENCIES) as $agencyCode) {
            for ($n = 0; $n < 14; $n++) {
                $sequence++;
                $female = $sequence % 3 === 0;
                $names = $female ? self::FIRST_NAMES_F : self::FIRST_NAMES_M;
                $first = $names[($sequence * 7) % count($names)];
                $last = self::LAST_NAMES[($sequence * 11) % count(self::LAST_NAMES)];
                $this->individual($agencyCode, $first, $last, 'TN', 'cin', sprintf('%08d', 3_100_000 + $sequence * 48_611 % 9_000_000), $sequence);
            }
        }

        foreach (self::FOREIGNERS as $index => [$first, $last, $country]) {
            $sequence++;
            $agencyCode = ['DJE', 'DJE', 'SOU', 'TUN'][$index % 4];
            $this->individual($agencyCode, $first, $last, $country, 'passport', sprintf('%s%07d', $country, 4_400_000 + $index * 3_181), $sequence);
        }

        foreach (self::COMPANIES as $index => [$name, $agencyCode]) {
            $sequence++;
            $result = app(CreateCounterCustomer::class)->handle([
                'type' => 'company', 'company_name' => $name, 'phone' => sprintf('%d %03d %03d', [71, 73, 74, 75][$index % 4], 200 + $index * 7, 100 + $index * 13),
                'license_number' => null, 'origin_agency_id' => $this->agencies[$agencyCode]->id,
            ] + $this->companyDocument($index), $this->actor);
            $customer = $result['customer'];
            $customer->forceFill([
                'tax_id' => sprintf('%07d%s/A/M/000', 1_500_000 + $index * 2_713, chr(65 + $index)),
                'email' => 'contact@'.Str::slug($name).'.test',
                'address' => self::STREETS[$index % count(self::STREETS)].' '.(10 + $index), 'city' => self::CITIES[$agencyCode][0], 'country' => 'TN',
                'acquisition_source' => 'referral',
            ])->save();
            $this->customers[$agencyCode][] = $customer;
            $this->count('Clients entreprises');

            // Deux conducteurs désignés par société (Q47).
            for ($d = 0; $d < 2; $d++) {
                $first = self::FIRST_NAMES_M[($index * 3 + $d) % count(self::FIRST_NAMES_M)];
                $last = self::LAST_NAMES[($index * 5 + $d + 3) % count(self::LAST_NAMES)];
                Driver::query()->create([
                    'customer_id' => $customer->id, 'first_name' => $first, 'last_name' => $last,
                    'birth_date' => $this->today->subYears(30 + ($index + $d) % 20)->subDays($index * 17),
                    'license_number' => sprintf('%02d/%06d', 10 + $index, 400000 + $index * 97 + $d),
                    'license_issued_on' => $this->today->subYears(8 + $d)->subDays($index * 11),
                    'license_expires_on' => $this->today->addYears(2 + $d)->addDays($index * 19),
                    'license_country' => 'TN', 'id_document_number' => sprintf('%08d', 7_100_000 + $index * 3_331 + $d),
                    'created_by' => $this->actor->id,
                ]);
                $this->count('Conducteurs');
            }
        }
    }

    /**
     * Une société est créée au comptoir avec une pièce : celle de son gérant (D40).
     *
     * @return array{id_document_type: string, id_document_number: string}
     */
    private function companyDocument(int $index): array
    {
        return ['id_document_type' => 'cin', 'id_document_number' => sprintf('%08d', 8_200_000 + $index * 4_421)];
    }

    private function individual(string $agencyCode, string $first, string $last, string $country, string $documentType, string $document, int $sequence): void
    {
        $tunisian = $country === 'TN';
        $license = $tunisian ? sprintf('%02d/%06d', 10 + $sequence % 80, 100000 + $sequence * 3_001 % 899_999) : sprintf('%s-DL-%06d', $country, 200000 + $sequence * 77);

        $result = app(CreateCounterCustomer::class)->handle([
            'type' => 'individual', 'first_name' => $first, 'last_name' => $last,
            'phone' => $tunisian ? sprintf('%d %03d %03d', [20, 22, 25, 50, 53, 55, 98, 97][$sequence % 8], 100 + $sequence * 37 % 900, 100 + $sequence * 53 % 900) : sprintf('+33 6 %02d %02d %02d %02d', $sequence % 100, ($sequence * 3) % 100, ($sequence * 7) % 100, ($sequence * 9) % 100),
            'id_document_type' => $documentType, 'id_document_number' => $document,
            'license_number' => $license, 'origin_agency_id' => $this->agencies[$agencyCode]->id,
        ], $this->actor);
        $customer = $result['customer'];

        // Âge de 19 à 68 ans ; quelques cas limites pour les règles des conducteurs (phase 5).
        $age = match ($sequence % 17) {
            0 => 20,
            1 => 22,
            default => 24 + ($sequence * 7) % 45,
        };
        $birth = $this->today->subYears($age)->subDays(($sequence * 41) % 360);
        $licenseYears = match ($sequence % 13) {
            0 => 1,      // permis trop récent
            1 => 2,
            default => min($age - 18, 3 + ($sequence * 5) % 25),
        };
        $licenseExpires = $sequence % 19 === 0 ? $this->today->subMonths(2) : $this->today->addYears(1 + $sequence % 9);
        $city = $tunisian ? self::CITIES[$agencyCode][$sequence % 4] : ['FR' => 'Lyon', 'DE' => 'Munich', 'IT' => 'Milan', 'DZ' => 'Annaba', 'LY' => 'Tripoli', 'BE' => 'Bruxelles'][$country];

        $customer->forceFill([
            'birth_date' => $birth, 'nationality' => $country,
            'email' => $sequence % 4 === 0 ? null : Str::slug($first.'.'.$last, '.').$sequence.'@exemple.test',
            'address' => (5 + $sequence % 90).', '.self::STREETS[$sequence % count(self::STREETS)], 'city' => $city, 'country' => $country,
            'id_document_expires_at' => $this->today->addYears(2 + $sequence % 8),
            'acquisition_source' => ['walk_in', 'website', 'referral', 'travel_agency', 'hotel'][$sequence % 5],
        ])->save();

        Driver::query()->where('customer_id', $customer->id)->update([
            'birth_date' => $birth->toDateString(),
            'license_issued_on' => $this->today->subYears($licenseYears)->subDays(($sequence * 13) % 200)->toDateString(),
            'license_expires_on' => $licenseExpires->toDateString(),
            'license_country' => $country,
        ]);

        $this->customers[$agencyCode][] = $customer;
        $this->count($tunisian ? 'Clients particuliers' : 'Clients étrangers');
        $this->count('Conducteurs');
    }

    private function recommendations(): void
    {
        $plan = [
            ['TUN', 1, 'blacklist', 'unpaid', 'Deux factures impayées après relances, dossier transmis au contentieux.'],
            ['SFX', 4, 'blacklist', 'damage', 'Dommages importants non déclarés, refus de signer l\'état des lieux de retour.'],
            ['DJE', 9, 'blacklist', 'fraud', 'Permis présenté non conforme lors d\'un précédent contrat.'],
            ['SOU', 2, 'watch', 'late_return', 'Trois retours en retard de plus de 4 heures.'],
            ['TUN', 6, 'watch', 'incident', 'Amendes de stationnement répétées.'],
            ['DJE', 3, 'watch', 'late_payment', 'Paiement du solde avec retard.'],
            ['SFX', 10, 'watch', 'behaviour', 'Comportement agressif au comptoir.'],
            ['DJE', 0, 'recommended', 'loyal', 'Client fidèle depuis 2019, aucun incident.'],
            ['SOU', 5, 'recommended', 'loyal', 'Loue chaque été pour sa famille.'],
            ['TUN', 0, 'recommended', 'corporate', 'Contact principal d\'un client entreprise.'],
            ['SFX', 1, 'recommended', 'loyal', 'Véhicules toujours rendus propres et à l\'heure.'],
        ];

        foreach ($plan as [$agencyCode, $index, $level, $reason, $comment]) {
            $customer = $this->customers[$agencyCode][$index] ?? null;

            if ($customer === null) {
                continue;
            }

            app(AddRecommendation::class)->handle($customer, ['level' => $level, 'reason_code' => $reason, 'comment' => $comment], $this->actor);
            $this->count('Recommandations');
        }
    }

    private function reservations(): void
    {
        $optionIds = Option::query()->where('is_active', true)->pluck('id', 'code')->all();
        $locations = AgencyLocation::query()->get()->groupBy('agency_id');
        $created = [];
        $failed = 0;

        foreach (array_keys(self::AGENCIES) as $agencyCode) {
            $agency = $this->agencies[$agencyCode];
            $vehicles = array_values(array_filter($this->fleet[$agencyCode], fn (Vehicle $vehicle): bool => $vehicle->refresh()->status === VehicleStatus::Available));
            $customers = $this->customers[$agencyCode];
            $agencyLocations = $locations->get($agency->id)?->pluck('id')->all() ?? [];

            foreach ($vehicles as $v => $vehicle) {
                // Deux à trois locations successives par véhicule sur les huit semaines à venir.
                $cursor = $this->today->addDays(($v * 3) % 6);

                for ($k = 0; $k < 3; $k++) {
                    $length = [2, 3, 4, 5, 7, 10, 14][($v + $k * 2) % 7];
                    $from = $cursor->addDays(1 + ($v + $k) % 4)->setTime([9, 10, 14, 16][($v + $k) % 4], [0, 30][$k % 2]);
                    $to = $from->addDays($length)->setTime([9, 11, 15, 18][($v * 2 + $k) % 4], 0);
                    $cursor = $to->startOfDay();

                    if ($from->greaterThan($this->today->addDays(56))) {
                        break;
                    }

                    $customer = $customers[($v * 3 + $k * 5) % count($customers)];
                    $options = [];

                    if (($v + $k) % 3 === 0 && isset($optionIds['CHILD_SEAT'])) {
                        $options[$optionIds['CHILD_SEAT']] = 1;
                    }

                    if (($v + $k) % 4 === 1 && isset($optionIds['GPS'])) {
                        $options[$optionIds['GPS']] = 1;
                    }

                    if (($v + $k) % 5 === 2 && isset($optionIds['EXTRA_DRIVER'])) {
                        $options[$optionIds['EXTRA_DRIVER']] = 1;
                    }

                    $reservation = $this->reserve($agency, $vehicle, $customer, $from, $to, $options, $agencyLocations, ($v + $k) % 7 === 0 ? null : $vehicle->id, 'confirmed');

                    if ($reservation === null) {
                        $failed++;

                        continue;
                    }

                    $created[] = $reservation;
                }
            }

            // Deux brouillons (devis enregistrés) et une réservation par catégorie sans véhicule.
            foreach ([['CIT', 12, 4], ['SUV', 20, 6]] as [$category, $inDays, $length]) {
                $from = $this->today->addDays($inDays)->setTime(10, 0);
                $this->reserve($agency, null, $customers[($inDays + 1) % count($customers)], $from, $from->addDays($length), [], $agencyLocations, null, 'draft', $this->categories[$category]);
            }
        }

        // Trois annulations motivées (scénario 2).
        foreach (array_slice($created, 2, 3) as $index => $reservation) {
            app(CloseReservation::class)->cancel($reservation, ['Le client a annulé son voyage.', 'Vol retardé, le client a reporté son séjour.', 'Doublon : réservation saisie deux fois par téléphone.'][$index], $this->actor);
            $this->count('Réservations annulées');
        }

        if ($failed > 0) {
            $this->warnings[] = "{$failed} réservations non créées (véhicule ou capacité indisponible) : c'est attendu, le contrôle de disponibilité les a refusées.";
        }
    }

    /**
     * @param  array<int, int>  $options
     * @param  list<int>  $locations
     */
    private function reserve(Agency $agency, ?Vehicle $vehicle, Customer $customer, CarbonImmutable $from, CarbonImmutable $to, array $options, array $locations, ?int $vehicleId, string $status, ?VehicleCategory $category = null): ?Reservation
    {
        $location = $locations === [] ? null : $locations[($from->day + $customer->id) % count($locations)];

        try {
            $reservation = app(SaveReservation::class)->handle(null, [
                'customer_id' => $customer->id, 'agency_id' => $agency->id,
                'vehicle_category_id' => $category->id ?? $vehicle->vehicle_category_id, 'vehicle_id' => $vehicleId,
                'starts_at' => $from->format('Y-m-d H:i'), 'ends_at' => $to->format('Y-m-d H:i'),
                'pickup_location_id' => $location, 'return_location_id' => $location,
                'options' => $options, 'status' => $status,
            ], $this->actor);
        } catch (Throwable) {
            return null;
        }

        $this->count($status === 'draft' ? 'Réservations en brouillon' : ($vehicleId === null ? 'Réservations sans véhicule' : 'Réservations confirmées'));

        return $reservation;
    }

    /** Montant entier en dinars, au format de saisie (« 1 250,000 » sans espace). */
    private function amount(int $value): string
    {
        return $value.',000';
    }
}
