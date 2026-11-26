<?php

// Clés absentes de la traduction française livrée par Filament 5 (lot L14 de la phase 4),
// fusionnées par Laravel avec celles du paquet. Parité testée : tests/Feature/Setup/FilamentTranslationTest.php.

return [
    'column_manager' => [
        'position' => 'Position :position sur :count.',
        'position_in_group' => 'Position :position sur :count dans :group.',
        'reordered' => ':column déplacée en position :position sur :count.',
        'reordered_deferred' => ':column déplacée en position :position sur :count. Appliquez les colonnes pour enregistrer vos changements.',
        'reordered_in_group' => ':column déplacée en position :position sur :count dans :group.',
        'reordered_in_group_deferred' => ':column déplacée en position :position sur :count dans :group. Appliquez les colonnes pour enregistrer vos changements.',
        'actions' => [
            'move_up' => [
                'label' => 'Monter :column',
            ],
            'move_down' => [
                'label' => 'Descendre :column',
            ],
            'reorder' => [
                'label' => 'Réordonner la colonne',
            ],
        ],
    ],
    'columns' => [
        'editable' => [
            'saving' => 'Enregistrement de la modification…',
            'saved' => 'Modification enregistrée.',
            'failure' => 'L\'enregistrement n\'a pas pu être confirmé. Actualisez la page avant de réessayer.',
            'unavailable' => 'Cet élément n\'est plus modifiable.',
        ],
        'icon' => [
            'boolean' => [
                'true' => 'Oui',
                'false' => 'Non',
            ],
        ],
        'select' => [
            'loading_message' => 'Chargement…',
            'no_options_message' => 'Aucune option disponible.',
            'no_search_results_message' => 'Aucune option ne correspond à votre recherche.',
            'placeholder' => 'Sélectionnez une option',
            'searching_message' => 'Recherche en cours…',
            'search_prompt' => 'Commencez à taper pour rechercher',
        ],
    ],
    'actions' => [
        'reorder_record' => [
            'label' => 'Réordonner l\'élément :key',
        ],
        'move_record_up' => [
            'label' => 'Monter :label',
        ],
        'move_record_down' => [
            'label' => 'Descendre :label',
        ],
        'toggle_record_content' => [
            'label' => 'Déplier ou replier l\'élément :key',
        ],
    ],
    'loading' => 'Chargement…',
    'reordering' => [
        'position' => ':label, position :position sur :count.',
        'moved' => ':label déplacé en position :position sur :count.',
    ],
    'result_count' => '{0} Aucun résultat|{1} :count résultat|[2,*] :count résultats',
];
