<?php

it('utilise le fuseau horaire Africa/Tunis', function () {
    expect(config('app.timezone'))->toBe('Africa/Tunis')
        ->and(date_default_timezone_get())->toBe('Africa/Tunis');
});

it('utilise le français comme langue et langue de repli', function () {
    expect(config('app.locale'))->toBe('fr')
        ->and(config('app.fallback_locale'))->toBe('fr');
});

it('fige la plateforme Composer sur PHP 8.3.16 (D6)', function () {
    /** @var array{config: array{platform: array{php: string}}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['config']['platform']['php'])->toBe('8.3.16');
});

it('tourne sur PHP 8.3', function () {
    expect(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION)->toBe('8.3');
});
