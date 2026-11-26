<?php

/*
 * D38 : aucun verrou de sérialisation par le cache dans app/. Son effet dépend du
 * magasin configuré (« array » en test, non partagé entre processus) ; les verrous
 * passent par App\Support\Locking\DatabaseLock ou par une ligne verrouillée.
 * Aucune exception à ce jour.
 */

const CACHE_LOCK_ALLOWED = [];

it('n\'utilise aucun verrou de cache pour sérialiser des saisies (D38)', function () {
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));

        if (in_array($relative, CACHE_LOCK_ALLOWED, true)) {
            continue;
        }

        // Analyse des jetons : les commentaires et les chaînes sont ignorés.
        $code = '';
        foreach (PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $token) {
            if (! $token->is([T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_WHITESPACE])) {
                $code .= $token->text;
            }
        }

        if (preg_match('/Cache::(lock|restoreLock)\(|->lock\(|LockProvider/', $code) === 1) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([]);
});
