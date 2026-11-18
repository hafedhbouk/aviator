<?php

declare(strict_types=1);

namespace App\Domain\Administration\Actions;

use App\Domain\Administration\Exceptions\AgencyAssignmentException;
use App\Models\Agency;
use App\Models\AgencyAssignment;
use App\Models\User;
use App\Support\Authorization\Permission;
use App\Support\Authorization\Role;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Affecte un utilisateur d'agence en remplacement dans une autre agence, entre deux
 * dates incluses, appréciées dans le fuseau Africa/Tunis (Q69). Réservé au super
 * admin (permission users.manage) ; chaque affectation est journalisée.
 */
final class AssignReplacementAgency
{
    public function handle(User $actor, User $user, int $agencyId, string $startsOn, string $endsOn): AgencyAssignment
    {
        if (! $actor->checkPermissionTo(Permission::UsersManage->value)) {
            throw new AuthorizationException('Seul le super admin crée une affectation de remplacement.');
        }

        if (! $user->hasProfile(Role::AgencyUser)) {
            throw AgencyAssignmentException::onlyAgencyUsers();
        }

        if ($user->agency_id === $agencyId) {
            throw AgencyAssignmentException::defaultAgency();
        }

        $agency = Agency::query()->findOrFail($agencyId);

        if (! $agency->is_active) {
            throw AgencyAssignmentException::inactiveAgency();
        }

        $start = CarbonImmutable::parse($startsOn, (string) config('app.timezone'))->toDateString();
        $end = CarbonImmutable::parse($endsOn, (string) config('app.timezone'))->toDateString();

        if ($end < $start) {
            throw AgencyAssignmentException::invalidPeriod();
        }

        if ($end < AgencyAssignment::today()) {
            throw AgencyAssignmentException::endsInThePast();
        }

        return DB::transaction(function () use ($actor, $user, $agency, $start, $end): AgencyAssignment {
            // Verrou sur le compte : deux affectations simultanées ne se chevauchent pas.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $overlaps = $user->agencyAssignments()
                ->where('agency_id', $agency->id)
                ->where('starts_on', '<=', $end)
                ->where('ends_on', '>=', $start)
                ->exists();

            if ($overlaps) {
                throw AgencyAssignmentException::overlaps();
            }

            $assignment = $user->agencyAssignments()->create([
                'agency_id' => $agency->id,
                'starts_on' => $start,
                'ends_on' => $end,
                'created_by' => $actor->getKey(),
            ]);

            activity('audit')
                ->causedBy($actor)
                ->performedOn($user)
                ->event('agency_assignment_created')
                ->withProperties(['agency' => $agency->code, 'starts_on' => $start, 'ends_on' => $end])
                ->log('Affectation de remplacement créée');

            return $assignment;
        });
    }
}
