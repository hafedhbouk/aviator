<?php

arch('pas de fonction de débogage oubliée dans le code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('le code applicatif est en types stricts côté domaine')
    ->expect('App\Domain')
    ->toUseStrictTypes();

arch('les règles métier ne dépendent pas de l\'interface Filament')
    ->expect('App\Domain')
    ->not->toUse(['Filament', 'Livewire']);

arch('les modèles ne contiennent pas de règle d\'interface')
    ->expect('App\Models')
    ->not->toUse(['Filament\Forms', 'Filament\Tables', 'Filament\Actions', 'Livewire']);

arch('aucune action d\'export Filament sans décision de CustomerDataAccess (RG-14, D13)')
    ->expect(['Filament\Actions\ExportAction', 'Filament\Actions\ExportBulkAction'])
    ->not->toBeUsed();
