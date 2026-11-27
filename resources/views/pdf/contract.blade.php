@extends('pdf.layout')

@php
    /** @var \App\Models\Contract $contract */
    /** @var \App\Domain\Rental\Documents\ContractDocuments $docs */
    $tz = config('app.timezone');
    $customer = $docs->customer($contract);
    $documentTypes = ['cin' => 'CIN', 'passport' => 'Passeport', 'residence_permit' => 'Titre de séjour'];
    $fuel = ['vide', '1/8', '1/4', '3/8', '1/2', '5/8', '3/4', '7/8', 'plein'];
    $signature = $docs->signatureDataUri($departure);
    $taxes = $contract->quote['taxes'] ?? null;
@endphp

@section('title', 'Contrat de location '.$contract->number)

@section('content')
    <h1>Contrat de location n° {{ $contract->number }}</h1>
    <p class="muted">Agence {{ $contract->agency?->name }} · statut : {{ $contract->status->label() }}
        @if ($contract->reservation_id) · réservation {{ $contract->reservation?->number }} @endif</p>

    <h2>Client</h2>
    <table class="grid">
        <tr><td class="label">Client</td><td>{{ $customer->displayName() }}</td></tr>
        @if ($customer->id_document_number)
            <tr><td class="label">Pièce d'identité</td><td>{{ $documentTypes[$customer->id_document_type] ?? 'Pièce' }} n° {{ $customer->id_document_number }}</td></tr>
        @endif
        @if ($customer->tax_id)
            <tr><td class="label">Matricule fiscal</td><td>{{ $customer->tax_id }}</td></tr>
        @endif
    </table>

    <h2>Conducteurs autorisés</h2>
    <table class="grid">
        <tr><th>Conducteur</th><th>Permis</th><th>Obtenu le</th><th>Valable jusqu'au</th></tr>
        @forelse ($contract->drivers as $driver)
            <tr>
                <td>{{ $driver->first_name }} {{ $driver->last_name }}@if ($driver->pivot?->is_primary) (principal)@endif</td>
                <td>{{ $driver->license_number }}@if ($driver->license_country && $driver->license_country !== 'TN') ({{ $driver->license_country }})@endif</td>
                <td>{{ $driver->license_issued_on?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $driver->license_expires_on?->format('d/m/Y') ?? 'non indiquée' }}</td>
            </tr>
        @empty
            <tr><td colspan="4">Aucun conducteur enregistré.</td></tr>
        @endforelse
    </table>

    <h2>Véhicule et période</h2>
    <table class="grid">
        <tr><td class="label">Véhicule</td><td>{{ $contract->vehicle->label() }} — catégorie {{ $contract->category?->name }}</td></tr>
        <tr><td class="label">Départ prévu</td><td>{{ $contract->starts_at->timezone($tz)->format('d/m/Y à H:i') }}
            @if ($contract->departed_at) (parti le {{ $contract->departed_at->timezone($tz)->format('d/m/Y à H:i') }}) @endif</td></tr>
        <tr><td class="label">Retour prévu</td><td>{{ $contract->ends_at->timezone($tz)->format('d/m/Y à H:i') }}
            @if ($contract->returned_at) (restitué le {{ $contract->returned_at->timezone($tz)->format('d/m/Y à H:i') }}) @endif</td></tr>
        @if ($departure && $departure->mileage !== null)
            <tr><td class="label">Au départ</td><td>{{ number_format($departure->mileage, 0, ',', ' ') }} km, carburant {{ $fuel[$departure->fuel_eighths ?? 0] }}</td></tr>
        @endif
    </table>

    <h2>Conditions</h2>
    <table class="grid">
        <tr><td class="label">Kilométrage</td><td>
            {{ $contract->hasUnlimitedMileage() ? 'Illimité' : $contract->included_km_per_day.' km par jour ; km supplémentaire '.($contract->extra_km_amount?->format() ?? 'non facturé').' HT' }}
        </td></tr>
        <tr><td class="label">Carburant</td><td>Même niveau au retour qu'au départ
            @if ($contract->fuel_tank_liters && $contract->fuel_liter_amount)
                ; carburant manquant facturé {{ $contract->fuel_liter_amount->format() }} HT le litre
                @if ($contract->fuel_service_amount), plus {{ $contract->fuel_service_amount->format() }} HT de frais de service @endif
            @endif
        </td></tr>
        <tr><td class="label">Retard</td><td>Au-delà de la tolérance : une journée au tarif journalier du contrat ({{ $contract->daily_rate_amount->format() }} HT)
            @if ($contract->late_hour_amount) ou {{ $contract->late_hour_amount->format() }} HT par heure, selon le réglage de l'agence @endif</td></tr>
        <tr><td class="label">Franchise</td><td>{{ $contract->insurance_excess_amount?->format() ?? 'sans plafond fixé' }}</td></tr>
        <tr><td class="label">Caution prévue</td><td>{{ $contract->deposit_expected_amount?->format() ?? 'à fixer au départ' }}</td></tr>
    </table>

    <h2>Montants (hors taxes, figés à la création)</h2>
    <table class="grid">
        <tr><td class="label">Location ({{ $contract->quote['billable_days'] ?? '—' }} jour(s) au départ)</td><td class="right">{{ $contract->rental_amount->format() }}</td></tr>
        @foreach ($contract->options as $option)
            <tr><td class="label">{{ $option->option?->name }} × {{ $option->quantity }}</td><td class="right">{{ $option->total_amount->format() }}</td></tr>
        @endforeach
        @foreach ($contract->extensions as $extension)
            <tr><td class="label">Prolongation jusqu'au {{ $extension->new_ends_at->timezone($tz)->format('d/m/Y H:i') }} ({{ $extension->added_days }} jour(s))</td><td class="right">{{ $extension->total_amount->format() }}</td></tr>
        @endforeach
        @if (! $contract->discount_amount->isZero())
            <tr><td class="label">Remise</td><td class="right">− {{ $contract->discount_amount->format() }}</td></tr>
        @endif
        <tr><td class="label"><strong>Total HT</strong></td><td class="right"><strong>{{ $contract->total_amount->format() }}</strong></td></tr>
        @if (is_array($taxes) && $contract->extensions->isEmpty())
            <tr><td class="label">Montant TTC indicatif au départ (timbre compris)</td><td class="right">{{ \App\Support\Money\Money::of((string) $taxes['net_to_pay'])->format() }}</td></tr>
        @endif
    </table>
    <p class="muted">Le présent contrat n'est pas une facture : la facture est établie à la restitution, avec ses taxes.</p>

    @if ($contract->charges->isNotEmpty())
        <h2>Frais constatés au retour (HT)</h2>
        <table class="grid">
            @foreach ($contract->charges as $charge)
                <tr><td class="label">{{ $charge->label }}</td><td class="right">{{ $charge->amount->format() }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>Signatures</h2>
    <table>
        <tr>
            <td style="width:50%">Le client, lu et approuvé, ainsi que les conditions générales jointes :<br>
                @if ($signature)
                    <img class="signature" src="{{ $signature }}" alt="Signature du client">
                @else
                    <br><br><span class="muted">Signature : ……………………………</span>
                @endif
            </td>
            <td style="width:50%">Pour {{ $company?->trade_name ?? 'Aviator' }} :<br><br><br><span class="muted">……………………………</span></td>
        </tr>
    </table>
@endsection
