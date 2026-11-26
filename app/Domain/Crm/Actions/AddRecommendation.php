<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Exceptions\CrmException;
use App\Enums\RecommendationLevel;
use App\Enums\RecommendationStatus;
use App\Models\Customer;
use App\Models\CustomerRecommendation;
use App\Models\User;
use App\Support\Agency\CurrentAgency;
use App\Support\Authorization\Role;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Première version (phase 4, lot L8) : le super admin enregistre une recommandation
 * effective, en ajout seul (RG-16). Les propositions des agences, leur approbation
 * et l'écran des recommandations arrivent en phase 7 (K-08 à K-11).
 */
final class AddRecommendation
{
    public function __construct(private readonly CurrentAgency $currentAgency) {}

    /**
     * @param  array<string, mixed>  $data  level, reason_code, comment
     */
    public function handle(Customer $customer, array $data, User $actor): CustomerRecommendation
    {
        if (! $actor->hasProfile(Role::SuperAdmin)) {
            throw CrmException::notAllowed('les recommandations');
        }

        $values = Validator::make($data, [
            'level' => ['required', Rule::enum(RecommendationLevel::class)],
            'reason_code' => ['required', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['level' => 'niveau', 'reason_code' => 'motif', 'comment' => 'commentaire'])->validate();

        return CustomerRecommendation::query()->create([
            'customer_id' => $customer->id,
            'author_id' => $actor->id,
            'agency_id' => $this->currentAgency->id($actor) ?? $actor->agency_id,
            'level' => $values['level'],
            'reason_code' => $values['reason_code'],
            'comment' => $values['comment'] ?? null,
            'status' => RecommendationStatus::Effective,
        ]);
    }
}
