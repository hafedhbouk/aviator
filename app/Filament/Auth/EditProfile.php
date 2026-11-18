<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Rules\PasswordDoesNotContainIdentity;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Changement du mot de passe depuis le profil (D19). Le nom et l'adresse e-mail
 * contrôlés sont ceux du formulaire : un utilisateur qui change aussi son nom ne
 * peut pas choisir un mot de passe qui le contient.
 */
class EditProfile extends BaseEditProfile
{
    protected function getPasswordFormComponent(): Component
    {
        /** @var TextInput $component */
        $component = parent::getPasswordFormComponent();

        return $component->rule(fn (Get $get): PasswordDoesNotContainIdentity => new PasswordDoesNotContainIdentity(
            self::stringOrNull($get('email')),
            self::stringOrNull($get('name')),
        ));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
