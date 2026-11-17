<?php

use Illuminate\Database\Eloquent\Model;

/*
 * Montants : jamais de float (CLAUDE.md). Un montant est un Money (brick/math,
 * DECIMAL(15,3)). L'application n'a aujourd'hui aucun besoin de nombre à virgule
 * flottante : le type float est donc interdit dans tout app/. Une exception future
 * (coordonnées GPS…) devra être ajoutée ici, nommément et sans rapport avec un montant.
 *
 * Pest arch ne sait pas viser un type déclaré : le test analyse les jetons PHP.
 */

/**
 * @return list<string>
 */
function floatUsages(string $code): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($code),
        fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));
    $found = [];

    foreach ($tokens as $i => $token) {
        $next = $tokens[$i + 1] ?? null;
        $previous = $tokens[$i - 1] ?? null;
        $isCall = $next?->text === '(' && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION]);

        if ($token->is(T_DOUBLE_CAST)) {
            $found[] = "conversion {$token->text} (ligne {$token->line})";
        } elseif ($token->is(T_STRING) && strtolower($token->text) === 'float') {
            $found[] = "type float (ligne {$token->line})";
        } elseif ($token->is(T_STRING) && in_array(strtolower($token->text), ['floatval', 'doubleval'], true) && $isCall) {
            $found[] = "{$token->text}() (ligne {$token->line})";
        }
    }

    return $found;
}

it('détecte bien les usages interdits (contrôle du test lui-même)', function () {
    $code = <<<'PHP'
        <?php
        // float dans un commentaire : ignoré
        function a(float $x): float { return (float) $x; }
        $b = floatval('1.5');
        $c = is_float($b);
        $d = '(float) dans une chaîne';
        PHP;

    expect(floatUsages($code))->toBe([
        'type float (ligne 3)',
        'type float (ligne 3)',
        'conversion (float) (ligne 3)',
        'floatval() (ligne 4)',
    ]);
});

it('n\'utilise jamais float dans app/ (type, conversion, floatval)', function () {
    $violations = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        foreach (floatUsages((string) file_get_contents($file->getPathname())) as $usage) {
            $violations[] = $file->getFilename().' : '.$usage;
        }
    }

    expect($violations)->toBe([]);
});

it('ne caste aucune colonne en float, double, real ou decimal : un montant passe par MoneyCast', function () {
    $violations = [];

    foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! is_subclass_of($class, Model::class)) {
            continue;
        }

        foreach ((new $class)->getCasts() as $column => $cast) {
            if (preg_match('/^(float|double|real|decimal(:.*)?)$/i', $cast) === 1) {
                $violations[] = "{$class}::{$column} ({$cast})";
            }
        }
    }

    expect($violations)->toBe([]);
});
