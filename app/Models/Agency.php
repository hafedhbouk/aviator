<?php

namespace App\Models;

use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agence d'Aviator (A-02) : Djerba (siège), Sfax, Sousse, Tunis.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_head_office
 * @property string $city
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'is_head_office', 'city', 'address', 'phone', 'email', 'is_active'])]
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_head_office' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Utilisateurs dont c'est l'agence par défaut.
     *
     * @return HasMany<User, $this>
     */
    public function defaultUsers(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Utilisateurs autorisés à agir sur cette agence (Q69).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
