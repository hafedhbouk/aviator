<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Text\SearchNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * D19 : le mot de passe ne contient ni l'identifiant de l'adresse e-mail, ni un
 * élément du nom (parties de 4 caractères ou plus), quelle que soit la casse.
 */
final class PasswordDoesNotContainIdentity implements ValidationRule
{
    private const MIN_PART_LENGTH = 4;

    public function __construct(
        private readonly ?string $email,
        private readonly ?string $name,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = SearchNormalizer::name((string) $value);
        $compact = str_replace(' ', '', $password);

        foreach ($this->identityParts() as $part) {
            if (str_contains($compact, $part)) {
                $fail('Le mot de passe ne doit contenir ni votre nom ni votre adresse e-mail.');

                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function identityParts(): array
    {
        $parts = [];

        if ($this->email !== null && str_contains($this->email, '@')) {
            $local = str_replace(' ', '', SearchNormalizer::name(strstr($this->email, '@', true) ?: ''));
            $parts[] = $local;
        }

        foreach (explode(' ', SearchNormalizer::name($this->name ?? '')) as $word) {
            $parts[] = $word;
        }

        return array_values(array_filter(
            array_unique($parts),
            fn (string $part): bool => mb_strlen($part) >= self::MIN_PART_LENGTH,
        ));
    }
}
