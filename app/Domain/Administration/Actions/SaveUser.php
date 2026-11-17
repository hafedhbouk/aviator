<?php

declare(strict_types=1);

namespace App\Domain\Administration\Actions;

use App\Domain\Administration\Exceptions\UserManagementException;
use App\Models\User;
use App\Support\Auth\SessionRevoker;
use App\Support\Authorization\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crée ou modifie un compte du back-office (J-01, J-02), avec les règles suivantes.
 *
 * - Seuls les profils admin et utilisateur d'agence s'attribuent ici ; un super
 *   admin se crée par « aviator:create-super-admin » et ne se modifie pas ici (Q2).
 * - Un utilisateur d'agence a une agence par défaut ; l'admin n'est rattaché à aucune
 *   agence. Les remplacements dans d'autres agences passent par AssignReplacementAgency (Q69).
 * - Personne ne se désactive soi-même.
 * - Un nouveau compte reçoit un mot de passe aléatoire inconnu de tous : son titulaire
 *   choisit le sien par le lien de réinitialisation (envoyé par l'interface).
 */
final class SaveUser
{
    public function __construct(private readonly SessionRevoker $sessions) {}

    /**
     * @param  array<string, mixed>  $data  Données du formulaire : name, email, role, agency_id, is_active
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

        $agencyId = $this->resolveAgency($role, $data);

        return DB::transaction(function () use ($user, $data, $role, $isActive, $agencyId, $actor): User {
            $user ??= new User(['password' => Str::password(64)]);
            $previousRole = $user->exists ? $user->profile() : null;
            $wasActive = $user->exists && $user->is_active;

            $user->fill([
                'name' => trim(self::string($data, 'name')),
                'email' => mb_strtolower(trim(self::string($data, 'email'))),
                'agency_id' => $agencyId,
                'is_active' => $isActive,
            ]);
            $user->save();

            $user->syncRoles([$role->value]);

            if ($wasActive && ! $isActive) {
                // Désactivation : toutes les sessions et jetons « se souvenir de moi » sont coupés.
                $sessions = $this->sessions->revokeAll($user);

                activity('security')
                    ->causedBy($actor)
                    ->performedOn($user)
                    ->event('user_deactivated')
                    ->withProperties(['revoked_sessions' => $sessions])
                    ->log('Compte désactivé, sessions coupées');
            }

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
     */
    private function resolveAgency(Role $role, array $data): ?int
    {
        if ($role !== Role::AgencyUser) {
            return null;
        }

        return is_numeric($data['agency_id'] ?? null)
            ? (int) $data['agency_id']
            : throw UserManagementException::agencyRequired();
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
