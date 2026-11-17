<?php

arch('pas de fonction de débogage oubliée dans le code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('le code applicatif est en types stricts côté domaine')
    ->expect('App\Domain')
    ->toUseStrictTypes();
