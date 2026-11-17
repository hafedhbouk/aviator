<?php

declare(strict_types=1);

namespace App\Support\Agency;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Document qui appartient à une agence (colonne agency_id, RG-13).
 *
 * - Lecture : AgencyScope.
 * - Création par un utilisateur d'agence : agence active par défaut (CurrentAgency).
 * - Écriture : refusée hors des agences autorisées, même si le scope est contourné
 *   ou si le formulaire est falsifié.
 *
 * @mixin Model
 */
trait BelongsToAgency
{
    public static function bootBelongsToAgency(): void
    {
        static::addGlobalScope(new AgencyScope);

        // « saving » précède « creating » : l'agence par défaut est attribuée ici, avant le contrôle.
        static::saving(function (Model $model): void {
            $user = Auth::user();

            if ($user instanceof User) {
                self::assignCurrentAgency($model, $user);
                self::assertAgenciesAllowed($model, $user);
            }
        });
    }

    private static function assignCurrentAgency(Model $model, User $user): void
    {
        if ($model->exists || $model->getAttribute('agency_id') !== null || $user->canActOnAllAgencies()) {
            return;
        }

        $model->setAttribute('agency_id', app(CurrentAgency::class)->id($user) ?? throw AgencyAccessException::noAgency());
    }

    /**
     * La nouvelle agence et, pour une modification, l'agence d'origine doivent être autorisées.
     */
    private static function assertAgenciesAllowed(Model $model, User $user): void
    {
        $agencyIds = [self::agencyIdOf($model->getAttribute('agency_id'))];

        if ($model->exists) {
            $agencyIds[] = self::agencyIdOf($model->getOriginal('agency_id'));
        }

        foreach (array_unique($agencyIds) as $agencyId) {
            if (! $user->canActOnAgency($agencyId)) {
                throw AgencyAccessException::notAuthorized($agencyId);
            }
        }
    }

    private static function agencyIdOf(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
