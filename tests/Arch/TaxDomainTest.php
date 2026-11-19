<?php

/*
 * Le domaine fiscal ne lit jamais un libellé (référentiel v1.0, §2.2 et §2.7 ; plan de
 * la phase 3, §6) : le traitement d'une ligne dépend de son code fiscal, jamais de sa
 * désignation. Aucune comparaison de texte, aucun accès à un libellé ou une description.
 */

it('ne lit aucun libellé dans les services qui décident du traitement fiscal', function () {
    // Services qui déterminent la règle, le taux, la TVA et le timbre d'une ligne. Les
    // actions d'écriture (versions, activation) contrôlent des formats de saisie : hors champ.
    $deciders = ['TaxRuleResolver.php', 'TaxCalculator.php', 'StampDutyResolver.php', 'TaxLine.php', 'TaxBreakdown.php', 'FiscalGuard.php'];
    $forbidden = [
        '->label', '->description', '->name', "'label'", "'description'", "'name'",
        'str_contains', 'stripos', 'strpos', 'preg_match', 'Str::contains', 'Str::lower', 'mb_strtolower', 'strtolower', ' like ',
    ];
    $offenders = [];

    foreach ($deciders as $name) {
        $file = new SplFileInfo(app_path('Domain/Tax/'.$name));
        expect($file->isFile())->toBeTrue("Fichier attendu : {$name}");
        $code = (string) file_get_contents($file->getPathname());

        // Les commentaires peuvent citer le mot « libellé » : seuls le code et les chaînes comptent.
        $tokens = array_filter(token_get_all($code), fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true));
        $stripped = implode('', array_map(fn ($t): string => is_array($t) ? $t[1] : $t, $tokens));

        foreach ($forbidden as $needle) {
            if (stripos($stripped, $needle) !== false) {
                $offenders[] = basename($file->getPathname()).' : '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

arch('les lignes à taxer n\'ont pas de libellé')
    ->expect('App\Domain\Tax\TaxLine')
    ->not->toHaveProperty('label');
