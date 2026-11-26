@php
    /** @var list<array{at: \Carbon\CarbonImmutable, kind: string, title: string, detail: string|null, by: string|null}> $entries */
    $entries = app(\App\Domain\Fleet\VehicleHistory::class)->for($getRecord());
@endphp

<div class="vehicle-history">
    <style>
        .vehicle-history table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .vehicle-history th, .vehicle-history td { text-align: left; padding: .4rem .5rem; border-bottom: 1px solid rgb(229 231 235 / .7); vertical-align: top; }
        .vehicle-history th { font-weight: 600; }
        .vehicle-history .vh-muted { color: rgb(107 114 128); }
        .vehicle-history .vh-scroll { overflow-x: auto; }
    </style>

    @if ($entries === [])
        <p class="vh-muted">Aucun événement enregistré.</p>
    @else
        <div class="vh-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Événement</th>
                        <th>Détail</th>
                        <th>Par</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td>{{ $entry['at']->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                            <td><span class="vh-muted">{{ $entry['kind'] }} :</span> {{ $entry['title'] }}</td>
                            <td>{{ $entry['detail'] ?? '—' }}</td>
                            <td>{{ $entry['by'] ?? 'Automatique' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="vh-muted">Les {{ \App\Domain\Fleet\VehicleHistory::LIMIT }} derniers événements. Contrats, maintenances et dépenses s'ajouteront à leur arrivée (phases 5 et 8).</p>
    @endif
</div>
