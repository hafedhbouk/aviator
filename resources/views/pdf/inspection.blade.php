@extends('pdf.layout')

@php
    /** @var \App\Models\Contract $contract */
    /** @var \App\Models\Inspection $inspection */
    /** @var \App\Domain\Rental\Documents\ContractDocuments $docs */
    $tz = config('app.timezone');
    $fuel = ['vide', '1/8', '1/4', '3/8', '1/2', '5/8', '3/4', '7/8', 'plein'];
    $signature = $docs->signatureDataUri($inspection);
    $photos = $docs->photoDataUris($inspection);
@endphp

@section('title', 'État des lieux de '.mb_strtolower($inspection->type->label()).' — contrat '.$contract->number)

@section('content')
    <h1>État des lieux de {{ mb_strtolower($inspection->type->label()) }}</h1>
    <p class="muted">Contrat {{ $contract->number }} · {{ $docs->customer($contract)->displayName() }} · {{ $contract->vehicle->label() }}</p>

    <table class="grid">
        <tr><td class="label">Kilométrage</td><td>{{ $inspection->mileage === null ? '—' : number_format($inspection->mileage, 0, ',', ' ').' km' }}</td></tr>
        <tr><td class="label">Carburant</td><td>{{ $inspection->fuel_eighths === null ? '—' : $fuel[$inspection->fuel_eighths] }}</td></tr>
        <tr><td class="label">Validé le</td><td>{{ $inspection->validated_at?->timezone($tz)->format('d/m/Y à H:i') ?? 'non validé (brouillon)' }}</td></tr>
        @if ($inspection->returned_at_declared)
            <tr><td class="label">Heure de retour déclarée</td><td>{{ $inspection->returned_at_declared->timezone($tz)->format('d/m/Y à H:i') }}</td></tr>
        @endif
        @if ($inspection->notes)
            <tr><td class="label">Observations</td><td>{{ $inspection->notes }}</td></tr>
        @endif
    </table>

    <h2>Dommages</h2>
    <table class="grid">
        <tr><th>Zone</th><th>Description</th><th>Gravité</th><th>État</th></tr>
        @forelse ($inspection->damages as $damage)
            <tr>
                <td>{{ $damage->zone->label() }}</td>
                <td>{{ $damage->description }}</td>
                <td>{{ $damage->severity->label() }}</td>
                <td>{{ $damage->is_new ? 'Nouveau' : 'Déjà présent' }}</td>
            </tr>
        @empty
            <tr><td colspan="4">Aucun dommage relevé.</td></tr>
        @endforelse
    </table>

    @if ($photos !== [])
        <h2>Photos ({{ count($photos) }} sur {{ $inspection->photos->count() }})</h2>
        <div class="photos">
            @foreach ($photos as $photo)
                <img src="{{ $photo }}" alt="Photo">
            @endforeach
        </div>
    @endif

    <h2>Signature du client</h2>
    @if ($signature)
        <img class="signature" src="{{ $signature }}" alt="Signature du client">
    @elseif ($inspection->customer_absent_reason)
        <p>Client absent : {{ $inspection->customer_absent_reason }}</p>
    @else
        <p class="muted">Non signé.</p>
    @endif
@endsection
