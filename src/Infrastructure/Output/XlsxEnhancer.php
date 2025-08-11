<?php
declare(strict_types=1);

namespace MapMissingItems\Infrastructure\Output;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use RuntimeException;

final class XlsxEnhancer
{
    /**
     * Post-process XLSX: add AutoFilter, freeze top row, auto-size columns, set alignment.
     * Safety: enhances only if rows <= $maxRows to avoid high memory usage on huge files.
     *
     * @param string $path
     * @param int $maxRows
     * @return void
     */
    public static function enhance(string $path, int $maxRows = 100000): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('File not found: ' . $path);
        }

        // Load workbook (this keeps full sheet in memory; guard with $maxRows)
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($path);

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();

        if ($highestRow > $maxRows) {
            // Avoid heavy post-processing on very large files
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            throw new RuntimeException("Too many rows for enhancement ({$highestRow} > {$maxRows}).");
        }

        // AutoFilter on whole used range
        $sheet->setAutoFilter("A1:{$highestCol}{$highestRow}");

        // Freeze first row
        $sheet->freezePane('A2');

        // Alignment: left for all columns, right for occurrences (column B)
        $sheet->getStyle("A1:{$highestCol}{$highestRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("B2:B{$highestRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Auto-size each column
        foreach (range('A', $highestCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Save back
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }
}
