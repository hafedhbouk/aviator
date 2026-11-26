<?php

use Illuminate\Support\Arr;

/*
 * Traductions françaises de Filament 5 (lot L14 de la phase 4, constat du lot L5) :
 * le paquet livre une traduction incomplète ; les clés absentes sont ajoutées dans
 * lang/vendor/<espace>/fr et fusionnées par Laravel. Même principe que D34 pour le
 * framework : une montée de version qui ajoute des clés fait échouer ce test.
 */

/**
 * Fichiers de traduction anglais des paquets Filament, avec leur espace de noms.
 *
 * @return array<string, array{string, string}>
 */
function filamentLanguageFiles(): array
{
    $namespaces = [
        'actions' => 'filament-actions', 'filament' => 'filament-panels', 'forms' => 'filament-forms',
        'infolists' => 'filament-infolists', 'notifications' => 'filament-notifications',
        'query-builder' => 'filament-query-builder', 'schemas' => 'filament-schemas', 'support' => 'filament',
        'tables' => 'filament-tables', 'widgets' => 'filament-widgets',
    ];
    $files = [];

    foreach (glob(base_path('vendor/filament/*/resources/lang/en/*.php')) ?: [] as $path) {
        $package = basename(dirname($path, 4));
        $files["{$package}/".basename($path)] = [$namespaces[$package] ?? '?', basename($path, '.php')];
    }

    return $files;
}

it('connaît l\'espace de noms de chaque paquet Filament traduit', function () {
    expect(collect(filamentLanguageFiles())->pluck(0)->contains('?'))->toBeFalse();
});

it('traduit en français toutes les clés de Filament', function () {
    $missing = [];

    foreach (filamentLanguageFiles() as $label => [$namespace, $file]) {
        $english = Arr::dot((array) trans("{$namespace}::{$file}", [], 'en'));
        $french = Arr::dot((array) trans("{$namespace}::{$file}", [], 'fr'));

        foreach (array_keys(array_diff_key($english, $french)) as $key) {
            $missing[] = "{$label} : {$key}";
        }
    }

    expect($missing)->toBe([]);
});

it('ne garde aucune surcharge française devenue sans objet', function () {
    $stale = [];

    foreach (glob(lang_path('vendor/*/fr/*.php')) ?: [] as $path) {
        $namespace = basename(dirname($path, 2));
        $file = basename($path, '.php');
        $english = Arr::dot((array) trans("{$namespace}::{$file}", [], 'en'));

        foreach (array_keys(array_diff_key(Arr::dot(require $path), $english)) as $key) {
            $stale[] = "{$namespace}/{$file} : {$key}";
        }
    }

    expect($stale)->toBe([]);
});

it('affiche les nouvelles traductions sans écraser celles du paquet', function () {
    expect(__('filament-tables::table.loading', [], 'fr'))->toBe('Chargement…')
        ->and(trans_choice('filament-tables::table.result_count', 3, [], 'fr'))->toBe('3 résultats')
        ->and(__('filament-panels::layout.skip_to_content.label', [], 'fr'))->toBe('Aller au contenu')
        // Clé déjà traduite par Filament : intacte.
        ->and(__('filament-tables::table.empty.heading', [], 'fr'))->not->toBe('filament-tables::table.empty.heading');
});
