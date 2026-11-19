<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Rapport de configuration fiscale — Aviator</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 20px 0 6px; }
        .fr-table { border-collapse: collapse; width: 100%; margin-bottom: 8px; }
        .fr-table th, .fr-table td { border: 1px solid #999; padding: 3px 5px; text-align: left; vertical-align: top; }
        .fr-narrow { width: auto; min-width: 50%; }
        .fr-amount { text-align: right; white-space: nowrap; }
        .fr-mono { font-family: ui-monospace, Consolas, monospace; word-break: break-all; }
        .fr-error { color: #b00020; font-weight: 600; }
        .fr-meta { color: #444; }
        .signatures { display: flex; gap: 48px; margin-top: 32px; }
        .signatures div { flex: 1; border-top: 1px solid #111; padding-top: 4px; min-height: 80px; }
        .no-print { margin-bottom: 16px; }
        @media print { .no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="no-print"><button type="button" onclick="window.print()">Imprimer</button></div>
    <h1>Rapport de configuration fiscale</h1>
    <p>{{ $companyName }}</p>

    @include('reports.partials.fiscal-configuration', ['report' => $report])

    <h2>Validation par la direction (avant la mise en production)</h2>
    <p>La direction certifie que les règles ci-dessus correspondent au référentiel fiscal validé et autorise leur usage en production.</p>
    <div class="signatures">
        <div>Nom, fonction, date</div>
        <div>Signature</div>
    </div>
</body>
</html>
