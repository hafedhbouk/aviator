<?php

namespace App\Filament\Forms;

use App\Support\Money\AmountInput;
use Filament\Forms\Components\TextInput;
use InvalidArgumentException;

/**
 * Champ de montant en dinars, au millime : « 150,500 ». La valeur part en chaîne ;
 * l'action de domaine la convertit en Money (AmountInput), jamais en float.
 */
class AmountField
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->suffix('DT')
            ->inputMode('decimal')
            ->regex(AmountInput::PATTERN)
            ->validationMessages(['regex' => 'Montant invalide : chiffres, virgule décimale, 3 décimales au plus.'])
            ->formatStateUsing(fn (mixed $state): ?string => self::display($state))
            ->placeholder('Non fixé');
    }

    private static function display(mixed $state): ?string
    {
        try {
            return AmountInput::display($state);
        } catch (InvalidArgumentException) {
            return is_string($state) ? $state : null;
        }
    }
}
