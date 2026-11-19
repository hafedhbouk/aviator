<?php

declare(strict_types=1);

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Exceptions\SetupException;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Crée ou modifie une agence (A-02 : ajout d'agences sans développement), avec les
 * règles suivantes.
 *
 * - Super admin et admin du siège seulement (AgencyPolicy).
 * - Code de 2 à 8 lettres majuscules ou chiffres, unique, **fixé à la création** :
 *   il identifie l'agence dans les documents et les exports.
 * - Le siège ne change pas ici : une nouvelle agence n'est jamais le siège, et le
 *   siège ne peut pas être désactivé.
 * - Une agence ne se désactive pas tant que des utilisateurs actifs y sont rattachés
 *   par défaut. Aucune agence n'est supprimée.
 * - Modification journalisée dans « audit » (avant, après), par AuditsChanges.
 */
final class SaveAgency
{
    /**
     * @param  array<string, mixed>  $data  code (création), name, city, address, phone, email, is_active
     */
    public function handle(?Agency $agency, array $data, User $actor): Agency
    {
        if (! $actor->can($agency === null ? 'create' : 'update', $agency ?? Agency::class)) {
            throw SetupException::notAllowed('les agences');
        }

        $values = $this->validated($data);

        return DB::transaction(function () use ($agency, $data, $values): Agency {
            if ($agency === null) {
                $agency = new Agency(['code' => $this->newCode($data['code'] ?? null), 'is_head_office' => false]);
            } else {
                $agency = Agency::query()->lockForUpdate()->findOrFail($agency->getKey());

                if (array_key_exists('code', $data) && self::string($data['code']) !== $agency->code) {
                    throw SetupException::agencyCodeImmutable();
                }
            }

            $isActive = (bool) ($data['is_active'] ?? true);

            if ($agency->exists && $agency->is_active && ! $isActive) {
                $this->assertCanDeactivate($agency);
            }

            $agency->fill($values + ['is_active' => $isActive]);
            $agency->save();

            return $agency;
        });
    }

    private function newCode(mixed $value): string
    {
        $code = mb_strtoupper(self::string($value) ?? '');

        if (preg_match('/^[A-Z0-9]{2,8}$/', $code) !== 1) {
            throw SetupException::agencyCodeInvalid();
        }

        if (Agency::query()->where('code', $code)->exists()) {
            throw SetupException::agencyCodeTaken($code);
        }

        return $code;
    }

    private function assertCanDeactivate(Agency $agency): void
    {
        if ($agency->is_head_office) {
            throw SetupException::headOfficeCannotBeDeactivated();
        }

        $activeUsers = User::query()->where('agency_id', $agency->id)->where('is_active', true)->count();

        if ($activeUsers > 0) {
            throw SetupException::agencyHasActiveUsers($activeUsers);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function validated(array $data): array
    {
        $values = [];

        foreach (['name', 'city', 'address', 'phone', 'email'] as $field) {
            $values[$field] = self::string($data[$field] ?? null);
        }

        if ($values['email'] !== null) {
            $values['email'] = mb_strtolower($values['email']);
        }

        /** @var array<string, string|null> */
        return Validator::make($values, [
            'name' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
        ], attributes: ['name' => 'nom', 'city' => 'ville'])->validate();
    }

    private static function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
