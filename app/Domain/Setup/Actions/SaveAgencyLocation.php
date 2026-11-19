<?php

declare(strict_types=1);

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Exceptions\SetupException;
use App\Domain\Setup\OpeningHours;
use App\Models\Agency;
use App\Models\AgencyLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Crée ou modifie un lieu de prise en charge ou de restitution (A-02), avec les
 * règles suivantes.
 *
 * - Super admin et admin du siège seulement (AgencyLocationPolicy) ; l'utilisateur
 *   d'agence lit les lieux de ses agences.
 * - Un lieu reste dans son agence ; son nom est unique dans l'agence.
 * - Il sert au moins à la prise en charge ou à la restitution.
 * - Horaires validés et normalisés par OpeningHours (plages HH:MM triées, sans
 *   chevauchement ; jour sans plage = fermé ; vide = non renseignés).
 * - Aucun lieu n'est supprimé (désactivation). Modification journalisée (audit).
 */
final class SaveAgencyLocation
{
    /**
     * @param  array<string, mixed>  $data  name, address, is_pickup, is_return, opening_hours, is_active
     */
    public function handle(?AgencyLocation $location, Agency $agency, array $data, User $actor): AgencyLocation
    {
        if (! $actor->can($location === null ? 'create' : 'update', $location ?? AgencyLocation::class)) {
            throw SetupException::notAllowed('les lieux des agences');
        }

        if ($location !== null && $location->agency_id !== $agency->id) {
            throw SetupException::notAllowed('le lieu d\'une autre agence');
        }

        $values = $this->validated($data);
        $isPickup = (bool) ($data['is_pickup'] ?? true);
        $isReturn = (bool) ($data['is_return'] ?? true);

        if (! $isPickup && ! $isReturn) {
            throw SetupException::locationWithoutUse();
        }

        $hours = OpeningHours::fromInput(self::hoursInput($data['opening_hours'] ?? null));

        return DB::transaction(function () use ($location, $agency, $values, $isPickup, $isReturn, $hours, $data): AgencyLocation {
            $taken = AgencyLocation::query()
                ->where('agency_id', $agency->id)
                ->where('name', $values['name'])
                ->when($location !== null, fn ($query) => $query->whereKeyNot($location?->getKey()))
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw SetupException::locationNameTaken((string) $values['name']);
            }

            $location ??= new AgencyLocation(['agency_id' => $agency->id]);

            $location->fill($values + [
                'is_pickup' => $isPickup,
                'is_return' => $isReturn,
                'opening_hours' => $hours->toArray(),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            $location->save();

            return $location;
        });
    }

    /**
     * Le formulaire envoie un tableau par jour (vide si fermé) : un formulaire sans
     * aucune plage signifie « horaires non renseignés ».
     */
    private static function hoursInput(mixed $input): mixed
    {
        if (! is_array($input)) {
            return $input;
        }

        $days = array_filter($input, fn (mixed $ranges): bool => $ranges !== null && $ranges !== []);

        return $days === [] ? null : array_map(
            fn (mixed $ranges): mixed => is_array($ranges) ? array_values($ranges) : $ranges,
            $input,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function validated(array $data): array
    {
        $values = [];

        foreach (['name', 'address'] as $field) {
            $value = $data[$field] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : '';
            $values[$field] = $value === '' ? null : $value;
        }

        /** @var array<string, string|null> */
        return Validator::make($values, [
            'name' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
        ], attributes: ['name' => 'nom du lieu'])->validate();
    }
}
