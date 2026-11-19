<?php

declare(strict_types=1);

namespace App\Domain\Tax\Actions;

use App\Domain\Tax\Exceptions\FiscalSetupException;
use App\Enums\FiscalValidationStatus;
use App\Models\StampDutyRule;
use App\Models\TaxRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Active ou désactive une règle de TVA ou de timbre : seule modification permise sur
 * place avec la clôture d'une version. Super admin seulement. Refuse d'activer une
 * règle PENDING_DOCUMENTATION, ou une règle CONFIRMED sans référence validée.
 * Journal « audit » : AuditsChanges (avant, après) et une entrée
 * « fiscal_rule_activation_changed ».
 */
final class SetFiscalRuleActive
{
    public function handle(TaxRule|StampDutyRule $rule, bool $active, User $actor): void
    {
        if (! $actor->can('update', $rule)) {
            throw FiscalSetupException::notAllowed();
        }

        if ($active && $rule->validation_status === FiscalValidationStatus::PendingDocumentation) {
            throw FiscalSetupException::cannotActivatePending();
        }

        if ($active && $rule->validation_status === FiscalValidationStatus::Confirmed && $rule->fiscal_reference_id === null) {
            throw FiscalSetupException::referenceRequired();
        }

        DB::transaction(function () use ($rule, $active, $actor): void {
            $locked = $rule::query()->lockForUpdate()->findOrFail($rule->getKey());

            if ($locked->is_active === $active) {
                return;
            }

            $locked->is_active = $active;
            $locked->save();

            activity('audit')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event('fiscal_rule_activation_changed')
                ->withProperties(['old' => ['is_active' => ! $active], 'attributes' => ['is_active' => $active]])
                ->log($active ? 'Règle fiscale activée' : 'Règle fiscale désactivée');

            $rule->is_active = $active;
        });
    }
}
