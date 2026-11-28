@extends('pdf.layout')

{{-- Facture (E-10, E8) : tout vient de la facture validée (client et émetteur figés, lignes, taxes enregistrées) ; rien n'est recalculé. --}}

@php
    /** @var \App\Models\Invoice $invoice */
    $tz = config('app.timezone');
    $agency = $issuer['agency'] ?? [];
    $money = fn (\App\Support\Money\Money $amount): string => $amount->format();
@endphp

@section('title', $invoice->type->label().' n° '.$invoice->number)

@section('header')
    <strong>{{ $issuer['trade_name'] ?? $issuer['legal_name'] ?? '' }}</strong>
    @if (! empty($issuer['legal_name'])) — {{ $issuer['legal_name'] }} @endif
    @if (! empty($issuer['trade_register'])) — RNE {{ $issuer['trade_register'] }} @endif
    @if (! empty($issuer['tax_id'])) — MF {{ $issuer['tax_id'] }} @endif
@endsection

@section('content')
    <h1>{{ $invoice->type->label() }} n° {{ $invoice->number }}</h1>
    <p class="muted">
        Date : {{ $invoice->validated_at?->timezone($tz)->format('d/m/Y') }}
        · Agence émettrice : {{ $agency['name'] ?? '' }}@if (! empty($agency['city'])), {{ $agency['city'] }}@endif
        @if ($contractNumber) · Contrat {{ $contractNumber }} @endif
        @if ($invoice->period_start && $invoice->period_end)
            · Période du {{ $invoice->period_start->timezone($tz)->format('d/m/Y H:i') }} au {{ $invoice->period_end->timezone($tz)->format('d/m/Y H:i') }}
        @endif
    </p>

    <table>
        <tr>
            <td style="width: 50%">
                <h2>Émetteur</h2>
                {{ $issuer['legal_name'] ?? '' }}<br>
                @if (! empty($issuer['address'])) {{ $issuer['address'] }}<br> @endif
                @if (! empty($issuer['city'])) {{ $issuer['city'] }}<br> @endif
                @if (! empty($issuer['tax_id'])) Matricule fiscal : {{ $issuer['tax_id'] }}<br> @endif
                @if (! empty($issuer['trade_register'])) RNE : {{ $issuer['trade_register'] }}<br> @endif
                @if (! empty($issuer['phone'])) Tél. : {{ $issuer['phone'] }} @endif
            </td>
            <td style="width: 50%">
                <h2>Client</h2>
                {{ $customer['name'] ?? '' }}<br>
                @if (! empty($customer['address'])) {{ $customer['address'] }}<br> @endif
                @if (! empty($customer['city']) || ! empty($customer['country'])) {{ trim(($customer['city'] ?? '').' '.($customer['country'] ?? '')) }}<br> @endif
                @if (! empty($customer['tax_id'])) Matricule fiscal : {{ $customer['tax_id'] }}<br> @endif
                @if (! empty($customer['trade_register'])) RNE : {{ $customer['trade_register'] }} @endif
            </td>
        </tr>
    </table>

    <h2>Détail</h2>
    <table class="grid">
        <tr><th>Désignation</th><th>Code fiscal</th><th class="right">Qté</th><th class="right">Prix unitaire HT</th><th class="right">Remise</th><th class="right">Total HT</th></tr>
        @foreach ($invoice->lines as $line)
            <tr>
                <td>{{ $line->label }}</td>
                <td>{{ $line->taxCode?->code }}</td>
                <td class="right">{{ str_replace('.', ',', rtrim(rtrim($line->quantity, '0'), '.')) }}</td>
                <td class="right">{{ $money($line->unit_amount_excl_tax) }}</td>
                <td class="right">{{ $line->discount_amount->isZero() ? '' : $money($line->discount_amount) }}</td>
                <td class="right">{{ $money($line->total_excl_tax_amount) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>Totaux (dinar tunisien)</h2>
    <table class="grid">
        @foreach ($rates as $rate)
            <tr><td class="label">Base {{ $rate['label'] }}</td><td class="right">{{ $money($rate['base']) }}</td><td class="right">{{ $rate['tax']->isZero() ? '' : $money($rate['tax']) }}</td></tr>
        @endforeach
        <tr><td class="label">Total HT</td><td class="right" colspan="2">{{ $money($invoice->total_excl_tax_amount) }}</td></tr>
        <tr><td class="label">Total TVA</td><td class="right" colspan="2">{{ $money($invoice->total_tax_amount) }}</td></tr>
        <tr><td class="label">Total TTC</td><td class="right" colspan="2">{{ $money($invoice->total_incl_tax_amount) }}</td></tr>
        <tr>
            <td class="label">Timbre fiscal</td>
            <td class="right" colspan="2">
                @if ($invoice->stamp_exemption_rule_id)
                    Exonéré — {{ $invoice->stamp_exemption_reason }}
                @else
                    {{ $money($invoice->stamp_duty_amount) }}
                @endif
            </td>
        </tr>
        <tr><td class="label"><strong>Net à payer</strong></td><td class="right" colspan="2"><strong>{{ $money($invoice->total_due_amount) }}</strong></td></tr>
    </table>

    @if ($invoice->due_on)
        <p>Échéance : {{ $invoice->due_on->format('d/m/Y') }}</p>
    @endif

    @if (! empty($issuer['legal_mentions']))
        <p class="muted">{!! nl2br(e($issuer['legal_mentions'])) !!}</p>
    @endif
@endsection
