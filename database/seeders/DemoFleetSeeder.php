<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Availability\Actions\SaveReservation;
use App\Domain\Crm\Actions\AddRecommendation;
use App\Domain\Crm\Actions\CreateCounterCustomer;
use App\Domain\Fleet\Actions\SaveVehicle;
use App\Domain\Fleet\Actions\SaveVehicleDocument;
use App\Domain\Fleet\Financing\Actions\SaveFinancing;
use App\Domain\Fleet\VehicleStatusService;
use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Données de démonstration FICTIVES pour l'atelier de fin de phase 4 (lot L15),
 * à charger en recette, **en plus** du paramétrage réel : jamais en production,
 * jamais appelé par DatabaseSeeder.
 *
 *     php artisan db:seed --class=DemoFleetSeeder
 *
 * Tout passe par les actions du domaine (contrôles, numéros, journal), sans
 * fabrique ni Faker (absents du serveur). Tout est marqué « DEMO » : codes
 * internes `DEMO-…`, clients au nom « DEMO ». Les réservations numérotées ne se
 * suppriment pas (RG-11) : la recette est réinitialisée après l'atelier si besoin.
 *
 * Prérequis : un super admin, des agences actives, une catégorie active avec une
 * grille tarifaire valide pour les deux semaines à venir.
 */
class DemoFleetSeeder extends Seeder
{
    public const string PREFIX = 'DEMO-';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Données de démonstration refusées en production.');
        }

        if (Vehicle::query()->withoutGlobalScopes()->where('internal_code', 'like', self::PREFIX.'%')->exists()) {
            $this->command?->warn('Données de démonstration déjà chargées : rien n\'est fait.');

            return;
        }

        $admin = User::query()->whereHas('roles', fn ($query) => $query->where('name', Role::SuperAdmin->value))->first()
            ?? throw new RuntimeException('Aucun super admin : lancez d\'abord « php artisan aviator:create-super-admin ».');
        $agencies = Agency::query()->where('is_active', true)->orderBy('id')->get();
        $category = VehicleCategory::query()->where('is_active', true)->orderBy('sort_order')->first()
            ?? throw new RuntimeException('Aucune catégorie active : saisissez d\'abord le paramétrage.');

        if ($agencies->isEmpty()) {
            throw new RuntimeException('Aucune agence active : saisissez d\'abord le paramétrage.');
        }

        Auth::login($admin);

        try {
            // Tout ou rien : un paramétrage incomplet ne laisse pas de démonstration partielle.
            $summary = DB::transaction(fn (): array => $this->load($admin, $agencies->all(), $category));
        } finally {
            Auth::logout();
        }

        $this->command?->info(sprintf(
            'Démonstration chargée : %d véhicules, %d clients, %d réservations, 1 leasing.',
            $summary['vehicles'],
            $summary['customers'],
            $summary['reservations'],
        ));
    }

    /**
     * @param  list<Agency>  $agencies
     * @return array{vehicles: int, customers: int, reservations: int}
     */
    private function load(User $admin, array $agencies, VehicleCategory $category): array
    {
        $today = CarbonImmutable::now((string) config('app.timezone'))->startOfDay();
        $counts = ['vehicles' => 0, 'customers' => 0, 'reservations' => 0];
        $first = $agencies[0];

        foreach ($agencies as $index => $agency) {
            $vehicles = [];

            for ($n = 1; $n <= 4; $n++) {
                $vehicles[] = $this->vehicle($admin, $agency, $category, $index * 10 + $n, $today->addYear());
                $counts['vehicles']++;
            }

            $customers = [];

            for ($n = 1; $n <= 2; $n++) {
                $customers[] = app(CreateCounterCustomer::class)->handle([
                    'type' => 'individual', 'first_name' => "Client {$n} {$agency->code}", 'last_name' => 'DEMO',
                    'phone' => sprintf('20 9%02d %03d', $index, $n), 'id_document_type' => 'cin',
                    'id_document_number' => sprintf('99%03d%03d', $index, $n), 'origin_agency_id' => $agency->id,
                ], $admin)['customer'];
                $counts['customers']++;
            }

            // Trois réservations par agence sur les deux semaines à venir (planning, démonstration 1).
            foreach ([[1, 3, 0, 0], [5, 8, 1, 1], [2, 4, 2, 0]] as [$from, $to, $vehicle, $customer]) {
                $this->reserve($admin, $agency, $category, $vehicles[$vehicle], $customers[$customer], $today->addDays($from)->setTime(9, 0), $today->addDays($to)->setTime(9, 0));
                $counts['reservations']++;
            }

            // Démonstration 6 : un véhicule en maintenance par agence.
            app(VehicleStatusService::class)->transition($vehicles[3], VehicleStatus::Maintenance, actor: $admin, reason: 'Vidange (démonstration)');
        }

        // Démonstration 6 (scénario 10) : assurance expirée hier, véhicule rendu indisponible.
        $expired = $this->vehicle($admin, $first, $category, 90, $today->subDay());
        // Démonstration 7 : assurance qui expire dans 10 jours (alerte, si son délai est réglé).
        $expiring = $this->vehicle($admin, $first, $category, 91, $today->addDays(10));
        $counts['vehicles'] += 2;
        unset($expired, $expiring);

        // Démonstration 4 : client sur liste noire inscrit dans la première agence.
        $blocked = app(CreateCounterCustomer::class)->handle([
            'type' => 'individual', 'first_name' => 'Client liste noire', 'last_name' => 'DEMO',
            'phone' => '20 999 999', 'id_document_type' => 'cin', 'id_document_number' => '99999999', 'origin_agency_id' => $first->id,
        ], $admin)['customer'];
        $counts['customers']++;
        $this->blacklist($blocked, $admin);

        // Démonstration 8 : un leasing en cours, avec son échéancier.
        $this->leasing($admin, $first, $category, $today);
        $counts['vehicles']++;

        return $counts;
    }

    private function vehicle(User $admin, Agency $agency, VehicleCategory $category, int $number, CarbonImmutable $insuranceUntil, string $financingMode = 'cash'): Vehicle
    {
        $vehicle = app(SaveVehicle::class)->handle(null, [
            'internal_code' => sprintf('%s%s-%02d', self::PREFIX, $agency->code, $number),
            'plate_number' => sprintf('9%02d TU %04d', $number, 9000 + $number),
            'vin' => sprintf('DEMOVIN%s%04d', $agency->code, $number),
            'vehicle_category_id' => $category->id, 'brand' => 'Démo', 'model' => 'Citadine', 'year' => '2025',
            'fuel' => 'petrol', 'transmission' => 'manual', 'agency_id' => $agency->id,
            'financing_mode' => $financingMode, 'initial_mileage' => (string) (10000 + 1000 * $number),
        ], $admin);

        app(SaveVehicleDocument::class)->handle($vehicle, null, [
            'type' => 'insurance', 'valid_from' => $insuranceUntil->subYear()->toDateString(), 'valid_until' => $insuranceUntil->toDateString(),
        ], $admin);
        app(SaveVehicleDocument::class)->handle($vehicle, null, [
            'type' => 'technical_inspection', 'valid_from' => $insuranceUntil->subYear()->toDateString(), 'valid_until' => $insuranceUntil->addYear()->toDateString(),
        ], $admin);

        return $vehicle->refresh();
    }

    private function reserve(User $admin, Agency $agency, VehicleCategory $category, Vehicle $vehicle, Customer $customer, CarbonImmutable $from, CarbonImmutable $to): void
    {
        try {
            app(SaveReservation::class)->handle(null, [
                'customer_id' => $customer->id, 'agency_id' => $agency->id, 'vehicle_category_id' => $category->id,
                'vehicle_id' => $vehicle->id, 'starts_at' => $from->format('Y-m-d H:i'), 'ends_at' => $to->format('Y-m-d H:i'),
            ], $admin);
        } catch (Throwable $exception) {
            throw new RuntimeException("Réservation de démonstration impossible ({$category->name}, {$agency->name}) : {$exception->getMessage()} Vérifiez la grille tarifaire et les saisons des deux semaines à venir.", previous: $exception);
        }
    }

    private function blacklist(Customer $customer, User $admin): void
    {
        app(AddRecommendation::class)->handle($customer, [
            'level' => 'blacklist', 'reason_code' => 'unpaid', 'comment' => 'Client fictif de démonstration (atelier de la phase 4).',
        ], $admin);
    }

    private function leasing(User $admin, Agency $agency, VehicleCategory $category, CarbonImmutable $today): void
    {
        $lessor = Supplier::query()->firstOrCreate(
            ['type' => 'leasing', 'name' => 'Société de leasing DEMO'],
            ['is_active' => true],
        );
        $vehicle = $this->vehicle($admin, $agency, $category, 92, $today->addYear(), 'leasing');
        $start = $today->subMonths(6)->startOfMonth()->addDays(14);

        app(SaveFinancing::class)->handle($vehicle, null, [
            'type' => 'leasing', 'supplier_id' => $lessor->id, 'reference' => 'DEMO-LS-001',
            'starts_on' => $start->toDateString(), 'first_due_on' => $start->addMonth()->toDateString(), 'ends_on' => $start->addYears(3)->toDateString(),
            'initial_payment_amount' => '8 000,000', 'monthly_amount_excl_tax' => '950,000', 'monthly_amount_incl_tax' => '1 130,500',
            'residual_value_amount' => '4 000,000', 'purchase_option_on' => $start->addYears(3)->toDateString(),
        ], $admin);
    }
}
