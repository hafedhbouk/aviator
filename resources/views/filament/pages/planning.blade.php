<x-filament-panels::page>
    <style>
        /* Blocs d'une autre agence ou sans lien : « Occupé », non cliquables (contournement 2, D35). */
        .planning-occupied { pointer-events: none; opacity: .85; }
        /* Retard : bordure rouge. */
        .planning-late { outline: 2px solid rgb(220 38 38); outline-offset: -2px; }
        [data-calendar] .ec-sidebar { width: 15rem; min-width: 15rem; }
        [data-calendar] .ec-sidebar .ec-row-head { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: .8125rem; }
        @media (max-width: 640px) {
            [data-calendar] .ec-sidebar { width: 7.5rem; min-width: 7.5rem; }
            [data-calendar] .ec-sidebar .ec-row-head { font-size: .6875rem; }
        }
        .planning-filters { display: flex; flex-wrap: wrap; gap: .75rem; align-items: end; }
        .planning-filters label { display: flex; flex-direction: column; gap: .25rem; font-size: .875rem; }
        .planning-filters select { min-width: 12rem; border-radius: .5rem; padding: .375rem .5rem; border: 1px solid rgb(209 213 219); background: transparent; }
    </style>

    <div class="planning-filters">
        <label>Agence
            <select wire:model.live="agencyId">
                <option value="">Toutes les agences</option>
                @foreach ($this->agencies() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label>Catégorie
            <select wire:model.live="categoryId">
                <option value="">Toutes les catégories</option>
                @foreach ($this->categories() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <p style="font-size:.8125rem;opacity:.75">
            Bleu : réservation ; vert : contrat ; orange : maintenance ; gris : immobilisation ;
            gris clair : « Occupé » (autre agence) ; fin hachurée : délai de préparation. Heure de Tunis.
            Clic sur une case libre : nouvelle réservation préremplie.
        </p>
    </div>

    @livewire(\App\Filament\Planning\PlanningCalendar::class, ['agencyId' => $this->agencyFilter(), 'categoryId' => $this->categoryFilter()], key('planning-'.($this->agencyFilter() ?? 'all').'-'.($this->categoryFilter() ?? 'all')))
</x-filament-panels::page>
