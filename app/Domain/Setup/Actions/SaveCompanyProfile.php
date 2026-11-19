<?php

declare(strict_types=1);

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Exceptions\SetupException;
use App\Models\Company;
use App\Models\User;
use App\Support\Files\PublicFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Enregistre la fiche société (A-01), avec les règles suivantes.
 *
 * - Super admin et admin du siège seulement (CompanyPolicy) ; une seule fiche,
 *   d'identifiant Company::SINGLETON_ID, créée au premier enregistrement.
 * - Devise : TND uniquement (D26) ; toute autre valeur est refusée.
 * - Mentions des factures : modifiables par le super admin seulement (paramètres
 *   fiscaux). Une clé absente des données laisse la valeur enregistrée intacte.
 * - Logo : image PNG, JPEG ou WebP du dossier public-files/company (D21) ; tout autre
 *   chemin est refusé, pour qu'aucun document privé ne devienne public par la fiche.
 *   L'ancien logo est effacé du disque après la validation de la transaction.
 * - Modification journalisée dans « audit » (avant, après), par AuditsChanges.
 */
final class SaveCompanyProfile
{
    private const IDENTITY_FIELDS = [
        'legal_name', 'trade_name', 'tax_id', 'trade_register', 'address', 'city', 'phone', 'email',
    ];

    /**
     * @param  array<string, mixed>  $data  legal_name, trade_name, tax_id, trade_register, address, city,
     *                                      phone, email, currency, logo_path, invoice_legal_mentions
     */
    public function handle(array $data, User $actor): Company
    {
        if (! $actor->can('update', Company::class)) {
            throw SetupException::notAllowed();
        }

        $currency = $data['currency'] ?? Company::CURRENCY;

        if ($currency !== Company::CURRENCY) {
            throw SetupException::currencyNotAllowed(is_scalar($currency) ? (string) $currency : '?');
        }

        $attributes = $this->validated($data);

        return DB::transaction(function () use ($data, $attributes, $actor): Company {
            $company = Company::query()->lockForUpdate()->find(Company::SINGLETON_ID)
                ?? (new Company)->forceFill(['id' => Company::SINGLETON_ID]);

            if (array_key_exists('invoice_legal_mentions', $data)) {
                $mentions = self::nullableString($data['invoice_legal_mentions']);

                if ($mentions !== $company->invoice_legal_mentions && ! $actor->can('updateLegalMentions', Company::class)) {
                    throw SetupException::legalMentionsNotAllowed();
                }

                $attributes['invoice_legal_mentions'] = $mentions;
            }

            $previousLogo = $company->logo_path;

            if (array_key_exists('logo_path', $data)) {
                $attributes['logo_path'] = $this->logoPath($data['logo_path']);
            }

            $company->fill($attributes + ['currency' => Company::CURRENCY]);
            $company->save();

            $newLogo = $company->logo_path;

            if ($previousLogo !== null && $previousLogo !== $newLogo && str_starts_with($previousLogo, PublicFiles::COMPANY_DIRECTORY.'/')) {
                DB::afterCommit(fn () => Storage::disk(PublicFiles::DISK)->delete($previousLogo));
            }

            return $company;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function validated(array $data): array
    {
        $values = [];

        foreach (self::IDENTITY_FIELDS as $field) {
            $values[$field] = self::nullableString($data[$field] ?? null);
        }

        if ($values['tax_id'] !== null) {
            $values['tax_id'] = mb_strtoupper($values['tax_id']);
        }

        if ($values['email'] !== null) {
            $values['email'] = mb_strtolower($values['email']);
        }

        /** @var array<string, string|null> $validated */
        $validated = Validator::make($values, [
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'trade_register' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
        ], attributes: [
            'legal_name' => 'raison sociale',
            'tax_id' => 'matricule fiscal',
            'trade_register' => 'identifiant RNE',
        ])->validate();

        return $validated;
    }

    private function logoPath(mixed $value): ?string
    {
        $path = self::nullableString($value);

        if ($path === null) {
            return null;
        }

        if (! str_starts_with($path, PublicFiles::COMPANY_DIRECTORY.'/')
            || ! PublicFiles::hasAllowedShape($path)
            || ! Storage::disk(PublicFiles::DISK)->exists($path)
            // Type réel, lu dans le contenu : un fichier renommé en .png est refusé.
            || Storage::disk(PublicFiles::DISK)->mimeType($path) !== PublicFiles::contentType($path)) {
            throw SetupException::invalidLogo();
        }

        return $path;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
