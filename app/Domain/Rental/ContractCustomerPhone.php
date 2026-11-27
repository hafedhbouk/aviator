<?php

declare(strict_types=1);

namespace App\Domain\Rental;

use App\Domain\Rental\Exceptions\ContractException;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Settings\ContractSettings;

/**
 * Téléphone du client sur un contrat en cours (Q4, D13) : pour le joindre en cas de
 * retard, l'agence du contrat peut l'afficher **seulement pendant la location**
 * (contrat « En cours »), si le réglage `contracts.show_phone_on_active_contract` le
 * permet. Chaque consultation est inscrite au journal `customer_access` (qui, quel
 * contrat, quel client), sans le numéro lui-même.
 */
final class ContractCustomerPhone
{
    public function __construct(private readonly ContractSettings $settings) {}

    public function canReveal(Contract $contract, User $actor): bool
    {
        return $this->settings->show_phone_on_active_contract
            && $contract->status === ContractStatus::Active
            && $actor->can('update', $contract);
    }

    /**
     * @return string|null le téléphone, ou null s'il n'est pas renseigné
     */
    public function reveal(Contract $contract, User $actor): ?string
    {
        if (! $this->canReveal($contract, $actor)) {
            throw ContractException::notAllowed('le téléphone du client (contrat en cours de votre agence seulement)');
        }

        $phone = Customer::query()->withoutGlobalScopes()->whereKey($contract->customer_id)->value('phone');

        activity('customer_access')
            ->causedBy($actor)
            ->performedOn($contract)
            ->event('contract_phone_viewed')
            ->withProperties(['customer_id' => $contract->customer_id, 'contract_id' => $contract->id])
            ->log('Téléphone du client consulté sur un contrat en cours');

        return is_string($phone) && $phone !== '' ? $phone : null;
    }
}
