@php
    /** @var \App\Filament\Pages\DailyMovements $this */
    $lists = ['Départs' => $this->departures(), 'Retours' => $this->returns()];
@endphp

<x-filament-panels::page>
    <style>
        .day-grid { display: grid; gap: 1rem; grid-template-columns: 1fr; }
        @media (min-width: 1100px) { .day-grid { grid-template-columns: 1fr 1fr; } }
        .day-card { border: 1px solid rgb(229 231 235); border-radius: .75rem; padding: 1rem; }
        .dark .day-card { border-color: rgb(63 63 70); }
        .day-card h2 { font-weight: 600; font-size: 1.05rem; margin-bottom: .5rem; }
        .day-item { display: flex; gap: .75rem; align-items: center; padding: .75rem .25rem; border-top: 1px solid rgb(243 244 246); min-height: 3.5rem; text-decoration: none; color: inherit; }
        .dark .day-item { border-color: rgb(39 39 42); }
        .day-item:hover { background: rgb(249 250 251); }
        .dark .day-item:hover { background: rgb(39 39 42); }
        .day-time { font-weight: 700; font-variant-numeric: tabular-nums; min-width: 3.5rem; }
        .day-badge { display: inline-block; font-size: .75rem; padding: .1rem .5rem; border-radius: 999px; background: rgb(254 243 199); color: rgb(146 64 14); margin: .15rem .25rem 0 0; }
        .day-badge-danger { background: rgb(254 226 226); color: rgb(153 27 27); }
    </style>

    <div class="day-grid">
        @foreach ($lists as $title => $rows)
            <div class="day-card">
                <h2>{{ $title }} ({{ count($rows) }})</h2>
                @forelse ($rows as $row)
                    <a class="day-item" href="{{ $row['url'] }}">
                        <span class="day-time">{{ $row['at'] }}</span>
                        <span style="flex:1">
                            <strong>{{ $row['customer'] }}</strong> · {{ $row['kind'] }} {{ $row['number'] }}<br>
                            <span style="font-size:.875rem;opacity:.8">{{ $row['vehicle'] }}</span><br>
                            @foreach ($row['badges'] as $badge)
                                <span class="day-badge {{ str_contains($badge, 'noire') || str_contains($badge, 'retard') || str_contains($badge, 'non valide') ? 'day-badge-danger' : '' }}">{{ $badge }}</span>
                            @endforeach
                        </span>
                    </a>
                @empty
                    <p style="opacity:.7">Aucun {{ mb_strtolower($title === 'Départs' ? 'départ' : 'retour') }} aujourd'hui.</p>
                @endforelse
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
