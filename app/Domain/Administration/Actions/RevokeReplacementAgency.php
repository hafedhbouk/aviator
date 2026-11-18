<?php

declare(strict_types=1);

namespace App\Domain\Administration\Actions;

use App\Domain\Administration\Exceptions\AgencyAssignmentException;
use App\Models\AgencyAssignment;
use App\Models\User;
use App\Support\Authorization\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Met fin à une affectation de remplacement avant son terme (super admin, Q69).
 * L'accès cesse immédiatement :
 * - une affectation qui n'a pas commencé avant aujourd'hui est supprimée ;
 * - une affectation en cours depuis plusieurs jours est close à la veille, et reste
 *   dans l'historique.
 * Une affectation terminée ne change plus. Chaque révocation est journalisée.
 */
final class RevokeReplacementAgency
{
    public function handle(User $actor, AgencyAssignment $assignment): void
    {
        if (! $actor->checkPermissionTo(Permission::UsersManage->value)) {
            throw new AuthorizationException('Seul le super admin met fin à une affectation de remplacement.');
        }

        $today = AgencyAssignment::today();

        if ($assignment->ends_on->toDateString() < $today) {
            throw AgencyAssignmentException::alreadyEnded();
        }

        $properties = [
            'agency_id' => $assignment->agency_id,
            'starts_on' => $assignment->starts_on->toDateString(),
            'ends_on' => $assignment->ends_on->toDateString(),
        ];

        if ($assignment->starts_on->toDateString() >= $today) {
            $assignment->delete();
            $properties['outcome'] = 'deleted';
        } else {
            $assignment->update(['ends_on' => CarbonImmutable::parse($today)->subDay()->toDateString()]);
            $properties['outcome'] = 'ended_yesterday';
        }

        activity('audit')
            ->causedBy($actor)
            ->performedOn($assignment->user)
            ->event('agency_assignment_revoked')
            ->withProperties($properties)
            ->log('Affectation de remplacement révoquée');
    }
}
