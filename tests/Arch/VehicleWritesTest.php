<?php

use Symfony\Component\Finder\Finder;

/*
 * Statut et kilométrage des véhicules : la garde du modèle Vehicle (événement
 * « saving ») n'écoute que les écritures par modèle. Une mise à jour de masse
 * (`Vehicle::query()->update(...)`, `$category->vehicles()->update(...)`) la
 * contournerait : interdite partout dans app/ (lot L14 de la phase 4).
 * Le SQL brut et DB::table() sont déjà interdits par DatabaseAccessTest.
 */

it('n\'écrit jamais les véhicules par une requête de masse', function () {
    $pattern = '/(Vehicle::[a-zA-Z]+\(|->vehicles\(\))[^;]*->(update|increment|decrement|incrementEach|decrementEach|upsert|insert|insertOrIgnore)\(/s';
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match($pattern, $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('détecte bien une mise à jour de masse (contrôle du motif)', function (string $code) {
    $pattern = '/(Vehicle::[a-zA-Z]+\(|->vehicles\(\))[^;]*->(update|increment|decrement|incrementEach|decrementEach|upsert|insert|insertOrIgnore)\(/s';

    expect(preg_match($pattern, $code))->toBe(1);
})->with([
    "Vehicle::query()->where('id', 1)->update(['status' => 'rented']);",
    "Vehicle::whereKey(\$ids)\n    ->increment('mileage', 10);",
    "\$category->vehicles()->update(['status' => 'available']);",
]);
