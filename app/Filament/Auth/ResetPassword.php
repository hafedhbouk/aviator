<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\User;
use App\Rules\PasswordDoesNotContainIdentity;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * Réinitialisation du mot de passe par lien (D19) : c'est par ce lien que chaque
 * nouveau compte choisit son premier mot de passe. En plus des règles de
 * Password::defaults(), le mot de passe ne doit contenir ni le nom ni l'adresse
 * e-mail du titulaire.
 */
class ResetPassword extends BaseResetPassword
{
    protected function getPasswordFormComponent(): Component
    {
        /** @var TextInput $component */
        $component = parent::getPasswordFormComponent();

        return $component->rule(fn (): PasswordDoesNotContainIdentity => new PasswordDoesNotContainIdentity(
            $this->email,
            $this->email === null ? null : User::query()->where('email', $this->email)->value('name'),
        ));
    }
}
