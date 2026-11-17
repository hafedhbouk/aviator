<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use App\Support\Authorization\Permission;
use App\Support\Authorization\Role;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int|null $agency_id
 * @property bool $is_active
 */
#[Fillable(['name', 'email', 'password', 'agency_id', 'is_active'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use AuditsChanges;

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;
    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * Valeurs jamais copiées dans le journal d'audit : seul leur changement est signalé.
     *
     * @return list<string>
     */
    public function sensitiveAuditAttributes(): array
    {
        return ['password', 'app_authentication_secret', 'app_authentication_recovery_codes'];
    }

    /**
     * Seuls les comptes actifs dotés d'un des trois profils accèdent au back-office.
     * Vérifié à la connexion et à chaque requête.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->profile() !== null;
    }

    /**
     * Profil de l'utilisateur (un seul par compte).
     */
    public function profile(): ?Role
    {
        foreach (Role::cases() as $role) {
            if ($this->hasRole($role->value)) {
                return $role;
            }
        }

        return null;
    }

    public function hasProfile(Role $role): bool
    {
        return $this->hasRole($role->value);
    }

    /**
     * Super admin et admin : action sur toutes les agences (permission agencies.all).
     */
    public function canActOnAllAgencies(): bool
    {
        return $this->checkPermissionTo(Permission::AgenciesAll->value);
    }

    /**
     * Agences sur lesquelles l'utilisateur peut agir aujourd'hui : son agence par
     * défaut, plus celles de ses affectations de remplacement actives à la date du
     * jour (Africa/Tunis, Q69). Vide pour un compte sans agence.
     *
     * @return list<int>
     */
    public function authorizedAgencyIds(): array
    {
        $ids = $this->agencyAssignments()
            ->activeOn()
            ->pluck('agency_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($this->agency_id !== null) {
            $ids[] = $this->agency_id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * L'utilisateur peut-il créer ou modifier un document de cette agence (RG-13) ?
     */
    public function canActOnAgency(?int $agencyId): bool
    {
        if ($this->canActOnAllAgencies()) {
            return true;
        }

        return $agencyId !== null && in_array($agencyId, $this->authorizedAgencyIds(), true);
    }

    /**
     * Agence par défaut de l'utilisateur.
     *
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * Affectations de remplacement (Q69), passées, en cours et à venir.
     *
     * @return HasMany<AgencyAssignment, $this>
     */
    public function agencyAssignments(): HasMany
    {
        return $this->hasMany(AgencyAssignment::class);
    }
}
