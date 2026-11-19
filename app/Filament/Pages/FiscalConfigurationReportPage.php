<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\TaxReporting\FiscalConfigurationReport;
use App\Domain\TaxReporting\FiscalReportSpreadsheet;
use App\Models\TaxCode;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Rapport de configuration fiscale (plan de la phase 3, §3.4) : écran, export XLSX et
 * version imprimable à faire signer par la direction. Super admin et admin (lecture).
 */
class FiscalConfigurationReportPage extends Page
{
    protected string $view = 'filament.pages.fiscal-configuration-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Paramètres fiscaux';

    protected static ?string $navigationLabel = 'Rapport de configuration';

    protected static ?string $title = 'Rapport de configuration fiscale';

    protected static ?string $slug = 'fiscal/rapport';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', TaxCode::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        return app(FiscalConfigurationReport::class)->build(now());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Exporter (XLSX)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): StreamedResponse => $this->export()),
            Action::make('print')
                ->label('Version imprimable')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (): string => route('filament.admin.fiscal-report.print'), shouldOpenInNewTab: true),
        ];
    }

    private function export(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $report = $this->getReport();
        $path = tempnam(sys_get_temp_dir(), 'aviator-fiscal-');

        app(FiscalReportSpreadsheet::class)->write($report, (string) $path);

        return response()->streamDownload(function () use ($path): void {
            readfile((string) $path);
            @unlink((string) $path);
        }, 'rapport_configuration_fiscale_'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
