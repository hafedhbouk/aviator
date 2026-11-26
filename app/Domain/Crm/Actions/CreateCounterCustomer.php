<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\CustomerRiskGate;
use App\Domain\Crm\Exceptions\CrmException;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\User;
use App\Support\Agency\CurrentAgency;
use App\Support\Locking\DatabaseLock;
use App\Support\Privacy\BlindIndex;
use App\Support\Text\SearchNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Création minimale d'un client au comptoir (parcours P1, étape 2 bis ; K-05 ; D40
 * validée avec condition). Fiche complète en phase 7.
 *
 * - **Condition de D40** : au moins le numéro de la pièce d'identité ou celui du permis.
 * - Avant toute écriture, recherche du même document par empreinte (pièce sur la fiche
 *   client ou sur un conducteur ; permis sur un conducteur), quelle que soit la saisie
 *   (espaces, tirets, casse) : si elle aboutit, **aucune nouvelle fiche** ; la fiche
 *   existante est renvoyée avec son niveau de risque. Un client sur liste noire ne peut
 *   donc pas réapparaître sous une nouvelle fiche (RG-15).
 * - Même téléphone qu'une fiche existante : simple avertissement (nombre de fiches),
 *   un numéro pouvant être partagé.
 * - Un particulier qui donne son permis devient aussi conducteur (Q47).
 * - Tous les profils, pour une agence sur laquelle ils agissent. Journal
 *   `customer_access` sans aucune valeur saisie.
 */
final class CreateCounterCustomer
{
    public const LOCK = 'crm:customers';

    public function __construct(
        private readonly BlindIndex $index,
        private readonly CustomerRiskGate $risk,
        private readonly CurrentAgency $currentAgency,
        private readonly DatabaseLock $lock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{customer: Customer, created: bool, blacklisted: bool, watched: bool, phone_matches: int}
     */
    public function handle(array $data, User $actor): array
    {
        $values = $this->validated($data);

        $agencyId = $this->originAgency($values['origin_agency_id'] ?? null, $actor);

        // Recherche du doublon et création sous un même verrou en base (D38) : deux
        // agents qui saisissent le même document au même moment ne créent qu'une fiche.
        return $this->lock->run(self::LOCK, function () use ($values, $agencyId, $actor): array {
            $existing = $this->findByDocument($values['id_document_number'], $values['license_number']);

            if ($existing !== null) {
                $this->record($actor, 'customer_matched', $existing->id);

                return [
                    'customer' => $existing,
                    'created' => false,
                    'blacklisted' => $this->risk->isBlacklisted($existing),
                    'watched' => $this->risk->isWatched($existing),
                    'phone_matches' => 0,
                ];
            }

            $phoneMatches = $values['phone'] === null ? 0 : Customer::query()
                ->where('phone_search', SearchNormalizer::phone($values['phone']))
                ->count();

            $customer = $this->create($values, $agencyId, $actor);
            $this->record($actor, 'customer_created', $customer->id);

            return ['customer' => $customer, 'created' => true, 'blacklisted' => false, 'watched' => false, 'phone_matches' => $phoneMatches];
        });
    }

    /**
     * @param  array{type: string, first_name: ?string, last_name: ?string, company_name: ?string, phone: ?string, id_document_type: ?string, id_document_number: ?string, license_number: ?string, origin_agency_id: ?int}  $values
     */
    private function create(array $values, int $agencyId, User $actor): Customer
    {
        return DB::transaction(function () use ($values, $agencyId, $actor): Customer {
            $customer = Customer::query()->create([
                'type' => $values['type'],
                'first_name' => $values['first_name'],
                'last_name' => $values['last_name'],
                'company_name' => $values['company_name'],
                'phone' => $values['phone'],
                'id_document_type' => $values['id_document_number'] !== null ? $values['id_document_type'] : null,
                'id_document_number' => $values['id_document_number'],
                'origin_agency_id' => $agencyId,
                'created_by' => $actor->id,
            ]);

            if ($values['license_number'] !== null && $values['type'] === CustomerType::Individual->value) {
                Driver::query()->create([
                    'customer_id' => $customer->id,
                    'first_name' => $values['first_name'],
                    'last_name' => $values['last_name'],
                    'license_number' => $values['license_number'],
                    'id_document_number' => $values['id_document_number'],
                    'created_by' => $actor->id,
                ]);
            }

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, first_name: ?string, last_name: ?string, company_name: ?string, phone: ?string, id_document_type: ?string, id_document_number: ?string, license_number: ?string, origin_agency_id: ?int}
     */
    private function validated(array $data): array
    {
        $text = function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;
            $value = $value instanceof \BackedEnum ? $value->value : $value;

            return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
        };

        $values = [
            'type' => $text('type') ?? CustomerType::Individual->value,
            'first_name' => $text('first_name'),
            'last_name' => $text('last_name'),
            'company_name' => $text('company_name'),
            'phone' => $text('phone'),
            'id_document_type' => $text('id_document_type'),
            'id_document_number' => $text('id_document_number'),
            'license_number' => $text('license_number'),
            'origin_agency_id' => $text('origin_agency_id'),
        ];

        // Condition de D40, contrôlée avant tout le reste.
        if ($values['id_document_number'] === null && $values['license_number'] === null) {
            throw CrmException::identifierRequired();
        }

        $individual = $values['type'] === CustomerType::Individual->value;

        $validated = Validator::make($values, [
            'type' => ['required', Rule::enum(CustomerType::class)],
            'first_name' => [$individual ? 'required' : 'nullable', 'string', 'max:100'],
            'last_name' => [$individual ? 'required' : 'nullable', 'string', 'max:100'],
            'company_name' => [$individual ? 'nullable' : 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9 ().\-]{8,32}$/'],
            'id_document_type' => [$values['id_document_number'] !== null ? 'required' : 'nullable', Rule::in(['cin', 'passport', 'residence_permit'])],
            'id_document_number' => ['nullable', 'string', 'max:64'],
            'license_number' => ['nullable', 'string', 'max:64'],
            'origin_agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
        ], attributes: [
            'first_name' => 'prénom', 'last_name' => 'nom', 'company_name' => 'raison sociale', 'phone' => 'téléphone',
            'id_document_type' => 'type de pièce', 'id_document_number' => 'numéro de pièce', 'license_number' => 'numéro de permis',
        ])->validate();

        $validated['origin_agency_id'] = $validated['origin_agency_id'] === null ? null : (int) $validated['origin_agency_id'];

        /** @var array{type: string, first_name: ?string, last_name: ?string, company_name: ?string, phone: ?string, id_document_type: ?string, id_document_number: ?string, license_number: ?string, origin_agency_id: ?int} */
        return $validated;
    }

    private function findByDocument(?string $idDocument, ?string $license): ?Customer
    {
        if ($idDocument !== null) {
            $hash = $this->index->hash($idDocument, Customer::ID_DOCUMENT_INDEX);

            $found = Customer::query()->where('id_document_hash', $hash)->orderBy('id')->first()
                ?? Driver::query()->where('id_document_hash', $hash)->orderBy('id')->first()?->customer;

            if ($found !== null) {
                return $found;
            }
        }

        if ($license !== null) {
            return Driver::query()
                ->where('license_hash', $this->index->hash($license, Driver::LICENSE_INDEX))
                ->orderBy('id')->first()?->customer;
        }

        return null;
    }

    private function originAgency(?int $requested, User $actor): int
    {
        if ($requested !== null) {
            if (! $actor->canActOnAgency($requested)) {
                throw CrmException::notAllowed('cette agence');
            }

            return $requested;
        }

        return $this->currentAgency->id($actor) ?? $actor->agency_id ?? throw CrmException::notAllowed('la création de clients sans agence');
    }

    private function record(User $actor, string $event, int $customerId): void
    {
        activity('customer_access')
            ->causedBy($actor)
            ->event($event)
            ->withProperties(['customer_id' => $customerId])
            ->log($event === 'customer_created' ? 'Client créé au comptoir' : 'Client existant retrouvé par son document');
    }
}
