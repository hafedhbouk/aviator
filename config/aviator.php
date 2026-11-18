<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Comptes super admin
    |--------------------------------------------------------------------------
    |
    | Nombre maximal de comptes super admin : le compte principal et un
    | compte de secours (Q2). La direction peut ramener cette valeur à 1.
    |
    */

    'max_super_admins' => (int) env('AVIATOR_MAX_SUPER_ADMINS', 2),

    /*
    |--------------------------------------------------------------------------
    | Empreintes de recherche (D8)
    |--------------------------------------------------------------------------
    |
    | Clé HMAC des empreintes qui permettent la recherche exacte sur des
    | numéros stockés chiffrés (pièce d'identité, permis). Secret propre à
    | chaque environnement, dans .env uniquement. La changer impose de
    | recalculer toutes les empreintes.
    |
    */

    'blind_index_key' => env('AVIATOR_BLIND_INDEX_KEY'),

];
