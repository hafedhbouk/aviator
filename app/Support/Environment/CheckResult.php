<?php

declare(strict_types=1);

namespace App\Support\Environment;

/**
 * Une ligne du contrôle d'environnement : statut, contrôle, constat, action conseillée.
 * Le constat ne contient jamais la valeur d'une clé ni d'un mot de passe.
 */
final readonly class CheckResult
{
    public function __construct(
        public CheckStatus $status,
        public string $check,
        public string $finding,
        public string $advice = '—',
    ) {}

    public static function ok(string $check, string $finding, string $advice = '—'): self
    {
        return new self(CheckStatus::Ok, $check, $finding, $advice);
    }

    public static function warning(string $check, string $finding, string $advice): self
    {
        return new self(CheckStatus::Warning, $check, $finding, $advice);
    }

    public static function error(string $check, string $finding, string $advice): self
    {
        return new self(CheckStatus::Error, $check, $finding, $advice);
    }
}
