<?php

use App\Rules\PasswordDoesNotContainIdentity;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

function passwordIsValid(string $password, ?string $email = 'sami.trabelsi@example.test', ?string $name = 'Sami Trabelsi'): bool
{
    return Validator::make(
        ['password' => $password],
        ['password' => [Password::defaults(), new PasswordDoesNotContainIdentity($email, $name)]],
    )->passes();
}

it('accepte un mot de passe conforme à D19', function () {
    expect(passwordIsValid('Location#Djerba-2026'))->toBeTrue();
});

it('refuse un mot de passe non conforme à D19', function (string $password) {
    expect(passwordIsValid($password))->toBeFalse();
})->with([
    '11 caractères' => ['Djerba#2026'],
    'sans majuscule' => ['location#djerba-2026'],
    'sans minuscule' => ['LOCATION#DJERBA-2026'],
    'sans chiffre' => ['Location#Djerba-Sfax'],
    'sans symbole' => ['LocationDjerba2026'],
    'plus de 128 caractères' => [str_repeat('Aa1#', 33)],
    'contient le prénom' => ['Sami#Location-2026'],
    'contient le nom, casse et accents différents' => ['TRÂBELSI#Location-26'],
    'contient l\'identifiant de l\'e-mail' => ['Samitrabelsi#2026x'],
]);

it('ignore les parties du nom de moins de 4 caractères', function () {
    expect(passwordIsValid('Ben#Location-Djerba26', name: 'Ali Ben'))->toBeTrue();
});

it('ne fait aucun appel en ligne (pas de vérification des mots de passe compromis)', function () {
    $rule = Password::defaults();

    $reflection = new ReflectionProperty($rule, 'uncompromised');

    expect($reflection->getValue($rule))->toBeFalse();
});
