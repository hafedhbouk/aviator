<?php

/*
 * Règle de CLAUDE.md : aucune lecture de données d'agence par DB::table() ou par du
 * SQL brut, sauf dans App\Domain\Statistics, qui applique la portée explicitement.
 * Les migrations (database/) ne sont pas concernées : seul app/ est analysé.
 *
 * Sont interdits :
 * - les appels statiques DB::table, DB::select, DB::selectOne, DB::raw, DB::statement,
 *   DB::unprepared, DB::affectingStatement, DB::scalar ;
 * - les mêmes appels sur une connexion : DB::connection(…)->select…(), ->statement()… ;
 * - les fragments SQL bruts du constructeur de requêtes : selectRaw, whereRaw,
 *   orWhereRaw, orderByRaw, havingRaw, orHavingRaw, groupByRaw, fromRaw.
 *
 * Pest arch ne sait pas viser un appel de méthode précis : le test analyse les jetons
 * PHP (les commentaires et les chaînes sont ignorés).
 */

const DB_FACADE_NAMES = ['DB', '\\DB', 'Illuminate\\Support\\Facades\\DB', '\\Illuminate\\Support\\Facades\\DB'];

/** Méthodes de la façade DB et d'une connexion qui exécutent ou fabriquent du SQL brut. */
const RAW_SQL_METHODS = ['table', 'select', 'selectone', 'selectfromwriteconnection', 'raw', 'statement', 'unprepared', 'affectingstatement', 'scalar', 'cursor'];

/** Fragments SQL bruts du constructeur de requêtes. */
const RAW_BUILDER_METHODS = ['selectraw', 'whereraw', 'orwhereraw', 'orderbyraw', 'havingraw', 'orhavingraw', 'groupbyraw', 'fromraw'];

/**
 * Exceptions déclarées, avec leur raison. Chemins relatifs à app/.
 *
 * @var array<string, string>
 */
const RAW_SQL_ALLOWED = [
    'Domain/Statistics/' => 'Statistiques : la portée par agence y est appliquée explicitement (CLAUDE.md)',
    'Support/Environment/EnvironmentProbe.php' => 'Contrôle de l\'environnement : lit des variables techniques de MariaDB (@@collation_database, @@max_connections…) et teste la connexion des séquences ; aucune donnée d\'agence',
];

/**
 * @return list<string> Appels interdits trouvés, par exemple « DB::table (ligne 12) »,
 *                      « DB::connection()->selectOne (ligne 3) » ou « ->whereRaw (ligne 7) »
 */
function forbiddenDbCalls(string $code): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($code),
        fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));
    $found = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // DB::méthode(…) et DB::connection(…)->méthode(…)
        if (in_array($token->text, DB_FACADE_NAMES, true) && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)) {
            $method = $tokens[$i + 2] ?? null;

            if (! $method?->is(T_STRING)) {
                continue;
            }

            if (in_array(strtolower($method->text), RAW_SQL_METHODS, true)) {
                $found[] = "DB::{$method->text} (ligne {$token->line})";

                continue;
            }

            if (strtolower($method->text) === 'connection' && ($tokens[$i + 3] ?? null)?->text === '(') {
                $after = skipParentheses($tokens, $i + 3);
                $arrow = $tokens[$after] ?? null;
                $chained = $tokens[$after + 1] ?? null;

                if ($arrow?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
                    && $chained?->is(T_STRING)
                    && in_array(strtolower($chained->text), RAW_SQL_METHODS, true)) {
                    $found[] = "DB::connection()->{$chained->text} (ligne {$token->line})";
                }
            }

            continue;
        }

        // ->selectRaw(…), ->whereRaw(…)…
        if ($token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            $method = $tokens[$i + 1] ?? null;

            if ($method?->is(T_STRING)
                && in_array(strtolower($method->text), RAW_BUILDER_METHODS, true)
                && ($tokens[$i + 2] ?? null)?->text === '(') {
                $found[] = "->{$method->text} (ligne {$token->line})";
            }
        }
    }

    return $found;
}

/**
 * Position du jeton qui suit la parenthèse fermante correspondant à celle de $open.
 *
 * @param  list<PhpToken>  $tokens
 */
function skipParentheses(array $tokens, int $open): int
{
    $depth = 0;

    for ($i = $open, $count = count($tokens); $i < $count; $i++) {
        $depth += match ($tokens[$i]->text) {
            '(' => 1,
            ')' => -1,
            default => 0,
        };

        if ($depth === 0) {
            return $i + 1;
        }
    }

    return $count;
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
        $f = DB::statement('drop table x');
        $g = DB::connection('mariadb_autocommit')->selectOne('select 1');
        $h = DB::connection()->select('select * from contracts');
        $i = DB::connection(config('x', fn () => ('y')))?->statement('update x');
        $j = Reservation::query()->whereRaw('agency_id = 1')->orderByRaw('id')->get();
        $k = $query->selectRaw('count(*) as n')->havingRaw('n > 1');
        $l = Reservation::query()->select(['id', 'number'])->where('id', 1)->get();
        $m = DB::connection('mariadb')->transaction(fn () => null);
        $n = DB::unprepared('set x = 1');
        PHP;

    expect(forbiddenDbCalls($code))->toBe([
        'DB::table (ligne 3)',
        'DB::select (ligne 4)',
        'DB::raw (ligne 5)',
        'DB::statement (ligne 8)',
        'DB::connection()->selectOne (ligne 9)',
        'DB::connection()->select (ligne 10)',
        'DB::connection()->statement (ligne 11)',
        '->whereRaw (ligne 12)',
        '->orderByRaw (ligne 12)',
        '->selectRaw (ligne 13)',
        '->havingRaw (ligne 13)',
        'DB::unprepared (ligne 16)',
    ]);
});

it('n\'utilise pas de SQL brut dans app/, hors des exceptions déclarées', function () {
    $appPath = str_replace('\\', '/', app_path()).'/';
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $relative = str_replace($appPath, '', str_replace('\\', '/', $file->getPathname()));

        if (! str_ends_with($relative, '.php')) {
            continue;
        }

        foreach (array_keys(RAW_SQL_ALLOWED) as $allowed) {
            if (str_starts_with($relative, $allowed)) {
                continue 2;
            }
        }

        foreach (forbiddenDbCalls((string) file_get_contents($file->getPathname())) as $call) {
            $violations[] = "app/{$relative} : {$call}";
        }
    }

    expect($violations)->toBe([]);
});

it('ne déclare que des exceptions qui existent et qui en ont besoin', function () {
    foreach (array_keys(RAW_SQL_ALLOWED) as $allowed) {
        $path = app_path($allowed);

        if (str_ends_with($allowed, '/')) {
            // Un dossier peut ne pas exister encore (App\Domain\Statistics arrive en phase 9).
            continue;
        }

        expect(is_file($path))->toBeTrue("Exception inconnue : app/{$allowed}")
            ->and(forbiddenDbCalls((string) file_get_contents($path)))->not->toBe([], "Exception inutile : app/{$allowed}");
    }
});
