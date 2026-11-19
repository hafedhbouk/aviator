<?php

declare(strict_types=1);

namespace App\Domain\TaxReporting;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Export XLSX du rapport de configuration fiscale (OpenSpout) : une feuille par partie.
 */
final class FiscalReportSpreadsheet
{
    /**
     * @param  array<string, mixed>  $report  FiscalConfigurationReport::build()
     */
    public function write(array $report, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Règles de TVA');
        $this->table($writer, ['Code', 'Libellé', 'Nature', 'Taux', 'Justificatif', 'Du', 'Au', 'Statut', 'Active', 'Référence', 'Base'],
            $report['rules'], ['code', 'label', 'nature', 'rate', 'justification', 'valid_from', 'valid_to', 'status', 'active', 'reference', 'legal_reference']);

        $writer->addNewSheetAndMakeItCurrent()->setName('Timbre');
        $this->table($writer, ['Règle', 'Description', 'Montant', 'Condition', 'Du', 'Au', 'Statut', 'Active', 'Référence', 'Base'],
            $report['stamps'], ['code', 'description', 'amount', 'condition', 'valid_from', 'valid_to', 'status', 'active', 'reference', 'legal_reference']);

        $writer->addNewSheetAndMakeItCurrent()->setName('Référentiel');
        $this->table($writer, ['Version', 'Titre', 'Fichier', 'SHA-256', 'Validation', 'Validé le', 'Attestation'],
            $report['references'], ['version', 'title', 'file_name', 'sha256', 'validation_source', 'validated_at', 'attestation']);
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Mode d\'arrondi de la TVA', $report['rounding_mode']]));
        $writer->addRow(Row::fromValues(['Rapport établi le', $report['generated_at'], 'Règles au', $report['as_of']]));

        $writer->addNewSheetAndMakeItCurrent()->setName('Exemple §5');
        $writer->addRow(Row::fromValues(['Désignation', 'Code', 'Montant']));

        foreach ($report['example']['lines'] as $line) {
            $writer->addRow(Row::fromValues([$line['label'], $line['code'], $line['amount']]));
        }

        foreach ($report['example']['totals'] as $total) {
            $writer->addRow(Row::fromValues([$total['label'], '', $total['amount']]));
        }

        if ($report['example']['error'] !== null) {
            $writer->addRow(Row::fromValues(['Calcul impossible', '', $report['example']['error']]));
        }

        $writer->close();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $keys
     */
    private function table(Writer $writer, array $headers, array $rows, array $keys): void
    {
        $writer->addRow(Row::fromValues($headers));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(fn (string $key): string => $row[$key], $keys)));
        }
    }
}
