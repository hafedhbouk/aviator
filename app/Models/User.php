<?php

namespace App\Models;

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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * Agence par défaut de l'utilisateur.
     *
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * Agences sur lesquelles l'utilisateur peut agir (Q69).
     *
     * @return BelongsToMany<Agency, $this>
     */
    public function agencies(): BelongsToMany
    {
        return $this->belongsToMany(Agency::class)->withTimestamps();
    }
}
