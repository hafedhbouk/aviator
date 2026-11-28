{{-- Gabarit commun des PDF (D-10, D43) : DejaVu Sans, A4, aucune ressource distante. --}}
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 22mm 16mm 18mm 16mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #111827; line-height: 1.35; }
        h1 { font-size: 15pt; margin: 0 0 4mm 0; }
        h2 { font-size: 11pt; margin: 5mm 0 2mm 0; border-bottom: 0.4pt solid #9ca3af; padding-bottom: 1mm; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 1.2mm 1.5mm; vertical-align: top; text-align: left; }
        .grid td { border: 0.4pt solid #d1d5db; }
        .label { color: #4b5563; width: 34%; }
        .right { text-align: right; }
        .muted { color: #6b7280; font-size: 8pt; }
        .header { position: fixed; top: -15mm; left: 0; right: 0; font-size: 8pt; color: #374151; }
        .footer { position: fixed; bottom: -11mm; left: 0; right: 0; font-size: 7.5pt; color: #6b7280; text-align: center; }
        .watermark { position: fixed; top: 105mm; left: -10mm; width: 200mm; text-align: center; font-size: 46pt; color: #dc2626; opacity: 0.16; transform: rotate(-30deg); }
        .signature { height: 26mm; }
        .photos img { width: 58mm; margin: 0 2mm 2mm 0; }
    </style>
</head>
<body>
    @if ($unvalidated)
        <div class="watermark">Modèle non validé</div>
    @endif
    @if (! empty($fiscalNotice))
        <div class="watermark">Règles fiscales non confirmées</div>
    @endif

    <div class="header">
        @hasSection('header')
            @yield('header')
        @else
            <strong>{{ $company?->trade_name ?? $company?->legal_name ?? 'Aviator' }}</strong>
            @if ($company?->legal_name) — {{ $company->legal_name }} @endif
            @if ($company?->trade_register) — RNE {{ $company->trade_register }} @endif
            @if ($company?->tax_id) — MF {{ $company->tax_id }} @endif
        @endif
    </div>

    <div class="footer">
        @yield('title') — édité le {{ now()->timezone(config('app.timezone'))->format('d/m/Y à H:i') }}
        @if ($unvalidated) — Modèle non validé par le conseil juridique (Q15) @endif
        @if (! empty($fiscalNotice)) — Règles fiscales non confirmées : document sans valeur fiscale (E6) @endif
    </div>

    @yield('content')
</body>
</html>
