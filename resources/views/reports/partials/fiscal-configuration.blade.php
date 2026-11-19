{{-- Contenu du rapport de configuration fiscale (page Filament et version imprimable). --}}
@php /** @var array<string, mixed> $report */ @endphp
<div class="fiscal-report">
    <p class="fr-meta">Règles en vigueur au {{ $report['as_of'] }} ; rapport établi le {{ $report['generated_at'] }}.</p>

    <h2>Référentiel validé</h2>
    @forelse ($report['references'] as $reference)
        <table class="fr-table">
            <tr><th>Version</th><td>{{ $reference['version'] }} — {{ $reference['title'] }}</td></tr>
            <tr><th>Fichier</th><td>{{ $reference['file_name'] }}</td></tr>
            <tr><th>SHA-256</th><td class="fr-mono">{{ $reference['sha256'] }}</td></tr>
            <tr><th>Validation</th><td>{{ $reference['validation_source'] }} ({{ $reference['validated_at'] }})</td></tr>
            <tr><th>Attestation</th><td>{{ $reference['attestation'] }}</td></tr>
        </table>
    @empty
        <p class="fr-error">Aucune référence fiscale validée n'est enregistrée.</p>
    @endforelse
    <p>Mode d'arrondi de la TVA : {{ $report['rounding_mode'] }}.</p>

    <h2>Codes fiscaux et règles de TVA</h2>
    <table class="fr-table">
        <thead>
            <tr><th>Code</th><th>Libellé</th><th>Nature</th><th>Taux</th><th>Justificatif</th><th>Du</th><th>Au</th><th>Statut</th><th>Active</th><th>Référence</th></tr>
        </thead>
        <tbody>
            @foreach ($report['rules'] as $rule)
                <tr>
                    <td class="fr-mono">{{ $rule['code'] }}</td><td>{{ $rule['label'] }}</td><td>{{ $rule['nature'] }}</td>
                    <td>{{ $rule['rate'] }}</td><td>{{ $rule['justification'] }}</td><td>{{ $rule['valid_from'] }}</td>
                    <td>{{ $rule['valid_to'] }}</td><td>{{ $rule['status'] }}</td><td>{{ $rule['active'] }}</td><td>{{ $rule['reference'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Timbre fiscal</h2>
    <table class="fr-table">
        <thead>
            <tr><th>Règle</th><th>Description</th><th>Montant</th><th>Condition</th><th>Du</th><th>Au</th><th>Statut</th><th>Active</th><th>Référence</th></tr>
        </thead>
        <tbody>
            @foreach ($report['stamps'] as $stamp)
                <tr>
                    <td class="fr-mono">{{ $stamp['code'] }}</td><td>{{ $stamp['description'] }}</td><td>{{ $stamp['amount'] }}</td>
                    <td>{{ $stamp['condition'] }}</td><td>{{ $stamp['valid_from'] }}</td><td>{{ $stamp['valid_to'] }}</td>
                    <td>{{ $stamp['status'] }}</td><td>{{ $stamp['active'] }}</td><td>{{ $stamp['reference'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Exemple chiffré du référentiel (§5), calculé par l'application</h2>
    <table class="fr-table fr-narrow">
        <tbody>
            @foreach ($report['example']['lines'] as $line)
                <tr><td>{{ $line['label'] }} <span class="fr-mono">({{ $line['code'] }})</span></td><td class="fr-amount">{{ $line['amount'] }}</td></tr>
            @endforeach
            @foreach ($report['example']['totals'] as $total)
                <tr class="fr-total"><th>{{ $total['label'] }}</th><td class="fr-amount">{{ $total['amount'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @if ($report['example']['error'])
        <p class="fr-error">Calcul impossible : {{ $report['example']['error'] }}</p>
    @endif
</div>
