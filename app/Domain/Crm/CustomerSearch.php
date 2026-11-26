<?php

declare(strict_types=1);

namespace App\Domain\Crm;

use App\Domain\Crm\Exceptions\CrmException;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\User;
use App\Support\Authorization\Role;
use App\Support\Privacy\BlindIndex;
use App\Support\Text\SearchNormalizer;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Recherche de clients au comptoir (K-04, RG-14, D24), pour tous les profils.
 *
 * - Un critère par recherche : nom complet exact (tout ordre, sans accents ni casse),
 *   numéro de pièce ou de permis (comparés par empreinte HMAC, numéros jamais lus en
 *   clair), téléphone harmonisé au format +216XXXXXXXX. Aucune recherche partielle.
 * - 10 résultats au plus : nom, liste noire oui ou non, nombre de locations. Ni
 *   coordonnées, ni liste complète, ni export (D13).
 * - Chaque recherche est inscrite au journal `customer_access` : utilisateur, critère,
 *   nombre de résultats, **jamais la valeur recherchée**.
 * - Au-delà de 30 recherches par heure pour un utilisateur, refus ; le super admin est
 *   prévenu une fois par heure, sans donnée client.
 */
final class CustomerSearch
{
    public const MAX_RESULTS = 10;

    public const MAX_PER_HOUR = 30;

    public const CRITERIA = ['name', 'id_document', 'license', 'phone'];

    public function __construct(
        private readonly BlindIndex $index,
        private readonly CustomerRiskGate $risk,
    ) {}

    /**
     * @return list<array{customer_id: int, name: string, blacklisted: bool, watched: bool, rentals: int}>
     */
    public function search(User $actor, string $criterion, string $value): array
    {
        if (! in_array($criterion, self::CRITERIA, true)) {
            throw new \InvalidArgumentException("Critère de recherche inconnu : {$criterion}.");
        }

        if (trim($value) === '') {
            throw CrmException::emptySearch();
        }

        $this->throttle($actor);

        $customers = match ($criterion) {
            'name' => Customer::query()->where('name_key', SearchNormalizer::nameKey($value)),
            'id_document' => Customer::query()->where(function ($query) use ($value): void {
                $hash = $this->index->hash($value, Customer::ID_DOCUMENT_INDEX);
                $query->where('id_document_hash', $hash)
                    ->orWhereIn('id', Driver::query()->where('id_document_hash', $hash)->select('customer_id'));
            }),
            'license' => Customer::query()->whereIn('id', Driver::query()
                ->where('license_hash', $this->index->hash($value, Driver::LICENSE_INDEX))
                ->select('customer_id')),
            'phone' => Customer::query()->where('phone_search', SearchNormalizer::phone($value)),
        };

        $found = $customers->whereNull('anonymized_at')
            ->orderBy('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        activity('customer_access')
            ->causedBy($actor)
            ->event('customer_search')
            ->withProperties(['criterion' => $criterion, 'results' => $found->count()])
            ->log('Recherche de clients');

        return $found->map(fn (Customer $customer): array => [
            'customer_id' => $customer->id,
            'name' => $customer->displayName(),
            'blacklisted' => $this->risk->isBlacklisted($customer),
            'watched' => $this->risk->isWatched($customer),
            // Contrats en phase 5 : aucun à ce jour.
            'rentals' => 0,
        ])->values()->all();
    }

    private function throttle(User $actor): void
    {
        $key = "customer-search:{$actor->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            activity('customer_access')
                ->causedBy($actor)
                ->event('customer_search_limit')
                ->withProperties(['limit' => self::MAX_PER_HOUR])
                ->log('Limite de recherches de clients atteinte');

            // Une seule alerte par heure et par utilisateur.
            RateLimiter::attempt("customer-search-alert:{$actor->id}", 1, fn () => $this->alertSuperAdmins($actor), 3600);

            throw CrmException::searchLimitReached(self::MAX_PER_HOUR);
        }

        RateLimiter::hit($key, 3600);
    }

    /**
     * Notification sans donnée client (RG-20) : qui, et le fait seulement.
     */
    private function alertSuperAdmins(User $actor): void
    {
        $recipients = User::query()->role(Role::SuperAdmin->value)->get();

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Recherches de clients : limite atteinte')
                ->body("{$actor->name} a dépassé ".self::MAX_PER_HOUR.' recherches de clients en une heure. Les suivantes sont refusées pendant une heure.')
                ->warning()
                ->sendToDatabase($recipient);
        }
    }
}
