<?php

/*
 * Règle de CLAUDE.md : aucune lecture de données d'agence par DB::table() ou par du
 * SQL brut, sauf dans App\Domain\Statistics, qui applique la portée explicitement.
 * Les migrations (database/) ne sont pas concernées : seul app/ est analysé.
 *
 * Pest arch ne sait pas viser un appel de méthode statique précis : le test analyse
 * les jetons PHP (les commentaires et les chaînes sont ignorés).
 */

const FORBIDDEN_DB_METHODS = ['table', 'select', 'raw'];

const DB_FACADE_NAMES = ['DB', '\\DB', 'Illuminate\\Support\\Facades\\DB', '\\Illuminate\\Support\\Facades\\DB'];

/**
 * @return list<string> Appels interdits trouvés, sous la forme « DB::table (ligne 12) »
 */
function forbiddenDbCalls(string $code): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($code),
        fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));
    $found = [];

    foreach ($tokens as $i => $token) {
        $method = $tokens[$i + 2] ?? null;

        if (in_array($token->text, DB_FACADE_NAMES, true)
            && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)
            && $method?->is(T_STRING)
            && in_array(strtolower($method->text), FORBIDDEN_DB_METHODS, true)) {
            $found[] = "DB::{$method->text} (ligne {$token->line})";
        }
    }

    return $found;
}

it('détecte bien les appels interdits (contrôle du test lui-même)', function () {
    $code = <<<'PHP'
        <?php
        // DB::table('commentaire ignoré');
        $a = DB::table('reservations')->get();
        $b = \Illuminate\Support\Facades\DB::select('select 1');
        $c = DB::raw('count(*)');
        $d = DB::transaction(fn () => null);
        $e = 'DB::table("chaîne ignorée")';
        PHP;

    expect(forbiddenDbCalls($code))->toBe([
        'DB::table (ligne 3)',
        'DB::select (ligne 4)',
        'DB::raw (ligne 5)',
    ]);
});

it('n\'utilise ni DB::table, ni DB::select, ni DB::raw dans app/ hors de App\Domain\Statistics', function () {
    $allowed = str_replace('\\', '/', app_path('Domain/Statistics'));
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if (! str_ends_with($path, '.php') || str_starts_with($path, $allowed.'/')) {
            continue;
        }

        foreach (forbiddenDbCalls((string) file_get_contents($path)) as $call) {
            $violations[] = str_replace(str_replace('\\', '/', base_path()).'/', '', $path).' : '.$call;
        }
    }

    expect($violations)->toBe([]);
});
