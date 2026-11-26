<?php

declare(strict_types=1);

namespace App\Domain\Rental;

use App\Domain\Setup\CategoryRules;
use App\Models\Driver;
use App\Models\VehicleCategory;
use Carbon\CarbonImmutable;

/**
 * Contrôle d'un conducteur pour une location (D-03, Q42), aux dates du contrat, au jour
 * de Tunis :
 *
 * - âge au départ ≥ âge minimal (surcharge de la catégorie, sinon réglage général) ;
 * - ancienneté du permis au départ ≥ ancienneté minimale (idem) ; le jour anniversaire
 *   compte ;
 * - permis valide jusqu'au **retour prévu** inclus ; une date de fin de validité non
 *   renseignée est signalée sans bloquer (anciens permis tunisiens sans échéance) ;
 * - permis étranger accepté et signalé à l'agent (Q42).
 *
 * Date de naissance et date d'obtention du permis sont obligatoires : sans elles, rien
 * ne peut être contrôlé.
 */
final class DriverEligibility
{
    public const LOCAL_COUNTRY = 'TN';

    /**
     * @return array{refusals: list<string>, warnings: list<string>, age: int|null, license_years: int|null, checked_for: string}
     */
    public function check(Driver $driver, VehicleCategory $category, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $rules = CategoryRules::for($category);
        $timezone = (string) config('app.timezone');
        $departureDay = $startsAt->timezone($timezone)->startOfDay();
        $returnDay = $endsAt->timezone($timezone)->startOfDay();
        $refusals = [];
        $warnings = [];
        $age = null;
        $licenseYears = null;

        if ($driver->birth_date === null) {
            $refusals[] = 'date de naissance non renseignée';
        } else {
            $age = (int) $driver->birth_date->startOfDay()->diffInYears($departureDay);

            if ($age < $rules->minDriverAge) {
                $refusals[] = "âge minimal de {$rules->minDriverAge} ans non atteint au départ ({$age} ans)";
            }
        }

        if ($driver->license_issued_on === null) {
            $refusals[] = 'date d\'obtention du permis non renseignée';
        } else {
            $licenseYears = (int) $driver->license_issued_on->startOfDay()->diffInYears($departureDay);

            if ($driver->license_issued_on->startOfDay()->greaterThan($departureDay)) {
                $licenseYears = 0;
            }

            if ($licenseYears < $rules->minLicenseYears) {
                $refusals[] = "permis de moins de {$rules->minLicenseYears} an(s) au départ";
            }
        }

        if ($driver->license_expires_on === null) {
            $warnings[] = 'Date de fin de validité du permis non renseignée : vérifiez le document.';
        } elseif ($driver->license_expires_on->startOfDay()->lessThan($returnDay)) {
            $refusals[] = $driver->license_expires_on->startOfDay()->lessThan($departureDay)
                ? 'permis expiré'
                : 'permis qui expire avant le retour prévu ('.$driver->license_expires_on->format('d/m/Y').')';
        }

        if ($driver->license_country !== null && strtoupper($driver->license_country) !== self::LOCAL_COUNTRY) {
            $warnings[] = 'Permis étranger ('.strtoupper($driver->license_country).') : vérifiez sa validité en Tunisie.';
        }

        return [
            'refusals' => $refusals,
            'warnings' => $warnings,
            'age' => $age,
            'license_years' => $licenseYears,
            'checked_for' => $departureDay->toDateString().'/'.$returnDay->toDateString(),
        ];
    }
}
