<x-filament-panels::page>
    <style>
        .fiscal-report h2 { font-size: 1rem; font-weight: 600; margin: 1.25rem 0 .5rem; }
        .fiscal-report .fr-table { width: 100%; border-collapse: collapse; font-size: .8rem; margin-bottom: .5rem; display: block; overflow-x: auto; }
        .fiscal-report .fr-table th, .fiscal-report .fr-table td { border: 1px solid rgb(209 213 219 / .6); padding: .25rem .4rem; text-align: left; vertical-align: top; }
        .fiscal-report .fr-amount { text-align: right; white-space: nowrap; }
        .fiscal-report .fr-mono { font-family: ui-monospace, monospace; word-break: break-all; }
        .fiscal-report .fr-error { color: rgb(220 38 38); font-weight: 600; }
    </style>

    @include('reports.partials.fiscal-configuration', ['report' => $this->getReport()])
</x-filament-panels::page>
