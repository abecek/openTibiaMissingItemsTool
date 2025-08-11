<?php
declare(strict_types=1);

namespace MapMissingItems\Infrastructure\Output;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

final class XlsxEnhancer
{
    /**
     * @param string $path
     * @param int $maxRows
     * @return void
     */
    public static function enhance(string $path, int $maxRows = 100000): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('File not found: ' . $path);
        }

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $highestRow = $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();
        if ($highestRow > $maxRows) {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            throw new RuntimeException("Too many rows for enhancement ({$highestRow} > {$maxRows}).");
        }

        // AutoFilter and freeze top row
        $sheet->setAutoFilter("A1:{$highestCol}{$highestRow}");
        $sheet->freezePane('A2');

        // Align all left by default
        $sheet->getStyle("A1:{$highestCol}{$highestRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Right-align occurrences by header name (not by fixed column letter)
        $occCol = self::findHeaderColumn($sheet, 'occurrences');
        if ($occCol !== null) {
            $sheet->getStyle("{$occCol}2:{$occCol}{$highestRow}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        // Image column: fix width and ensure min row height
        $imgCol = self::findHeaderColumn($sheet, 'image');
        if ($imgCol !== null) {
            $sheet->getColumnDimension($imgCol)->setAutoSize(false);
            $sheet->getColumnDimension($imgCol)->setWidth(12.5);
            for ($r = 2; $r <= $highestRow; $r++) {
                $current = (float)$sheet->getRowDimension($r)->getRowHeight();
                if ($current < 48.0) {
                    $sheet->getRowDimension($r)->setRowHeight(48.0);
                }
            }
        }

        // Auto-size all columns except image
        $maxIndex = Coordinate::columnIndexFromString($highestCol);
        for ($i = 1; $i <= $maxIndex; $i++) {
            $col = Coordinate::stringFromColumnIndex($i);
            if ($imgCol !== null && $col === $imgCol) {
                continue;
            }
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    /**
     * @param Worksheet $sheet
     * @param string $headerNameLower
     * @return string|null
     */
    private static function findHeaderColumn(Worksheet $sheet, string $headerNameLower): ?string
    {
        $highestCol = $sheet->getHighestDataColumn();
        $maxIndex   = Coordinate::columnIndexFromString($highestCol);
        for ($i = 1; $i <= $maxIndex; $i++) {
            $col = Coordinate::stringFromColumnIndex($i);
            $val = (string)$sheet->getCell($col . '1')->getValue();
            if (mb_strtolower(trim($val)) === mb_strtolower($headerNameLower)) {
                return $col;
            }
        }
        return null;
    }
}