<x-filament-panels::page>
    <style>
        /* Blocs d'une autre agence : « Occupé », ni lien ni modèle, non cliquables (Q38). */
        .planning-occupied { pointer-events: none; opacity: .85; }
        /* Colonne des véhicules lisible. */
        [data-calendar] .ec-sidebar { width: 15rem; min-width: 15rem; }
        [data-calendar] .ec-sidebar .ec-row-head { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; justify-content: flex-start; font-size: .8125rem; }
        @media (max-width: 640px) {
            [data-calendar] .ec-sidebar { width: 7.5rem; min-width: 7.5rem; }
            [data-calendar] .ec-sidebar .ec-row-head { font-size: .6875rem; }
        }
        /* Retard : bordure rouge. */
        .planning-late { outline: 2px solid rgb(220 38 38); outline-offset: -2px; }
    </style>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Données fictives générées en mémoire, sans lien avec la base. Lecture seule (D39).
        Légende : bleu réservation, vert contrat, orange maintenance, gris immobilisation,
        gris clair « Occupé » (autre agence), hachuré délai de préparation, bordure rouge retard.
    </p>

    @livewire($this->getWidgetClass())
</x-filament-panels::page>
