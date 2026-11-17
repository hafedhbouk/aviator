<?php

declare(strict_types=1);

namespace App\Domain\Administration\Actions;

use App\Domain\Administration\Exceptions\UserManagementException;
use App\Models\User;
use App\Support\Authorization\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crée ou modifie un compte du back-office (J-01, J-02), avec les règles suivantes.
 *
 * - Seuls les profils admin et utilisateur d'agence s'attribuent ici ; un super
 *   admin se crée par « aviator:create-super-admin » et ne se modifie pas ici (Q2).
 * - Un utilisateur d'agence a une agence par défaut, toujours comprise dans ses
 *   agences autorisées (Q69) ; l'admin n'est rattaché à aucune agence.
 * - Personne ne se désactive soi-même.
 * - Un nouveau compte reçoit un mot de passe aléatoire inconnu de tous : son titulaire
 *   choisit le sien par le lien de réinitialisation (envoyé par l'interface).
 */
final class SaveUser
{
    /**
     * @param  array<string, mixed>  $data  Données du formulaire : name, email, role, agency_id, agency_ids, is_active
     */
    public function handle(?User $user, array $data, User $actor): User
    {
        $role = Role::tryFrom(self::string($data, 'role'));

        if ($role === null || ! in_array($role, Role::assignableFromInterface(), true)) {
            throw UserManagementException::roleNotAssignable();
        }

        if ($user !== null && $user->hasProfile(Role::SuperAdmin)) {
            throw UserManagementException::superAdminManagedFromConsole();
        }

        $isActive = (bool) ($data['is_active'] ?? true);

        if ($user !== null && $user->is($actor) && ! $isActive) {
            throw UserManagementException::cannotDeactivateSelf();
        }

        [$agencyId, $agencyIds] = $this->resolveAgencies($role, $data);

        return DB::transaction(function () use ($user, $data, $role, $isActive, $agencyId, $agencyIds, $actor): User {
            $user ??= new User(['password' => Str::password(64)]);
            $previousRole = $user->exists ? $user->profile() : null;

            $user->fill([
                'name' => trim(self::string($data, 'name')),
                'email' => mb_strtolower(trim(self::string($data, 'email'))),
                'agency_id' => $agencyId,
                'is_active' => $isActive,
            ]);
            $user->save();

            $user->syncRoles([$role->value]);
            $user->agencies()->sync($agencyIds);

            if ($previousRole !== $role) {
                activity('audit')
                    ->causedBy($actor)
                    ->performedOn($user)
                    ->event('role_changed')
                    ->withProperties(['old' => $previousRole?->value, 'new' => $role->value])
                    ->log('Profil de l\'utilisateur modifié');
            }

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int|null, 1: list<int>}
     */
    private function resolveAgencies(Role $role, array $data): array
    {
        if ($role !== Role::AgencyUser) {
            return [null, []];
        }

        $agencyId = is_numeric($data['agency_id'] ?? null) ? (int) $data['agency_id'] : null;

        if ($agencyId === null) {
            throw UserManagementException::agencyRequired();
        }

        $others = is_array($data['agency_ids'] ?? null) ? $data['agency_ids'] : [];
        $agencyIds = [$agencyId];

        foreach ($others as $id) {
            if (is_numeric($id)) {
                $agencyIds[] = (int) $id;
            }
        }

        return [$agencyId, array_values(array_unique($agencyIds))];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
