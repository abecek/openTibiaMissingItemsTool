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
     * Post-process XLSX:
     *  - AutoFilter + freeze top row
     *  - Left align all; right align "occurrences"
     *  - Fix "image" column width (~64 px) and ensure minimal row height (~48pt ≈ 64px) on data rows
     *  - Auto-size all other columns
     *
     * Progres:
     *  $progress(string $message, int $advanceBy = 1, ?int $setMax = null)
     *   - na starcie wywołujemy $progress('init', 0, $totalSteps)
     *   - potem przekazujemy delta kroków i krótkie komunikaty
     *
     * @param string        $path
     * @param int           $maxRows   twardy limit (bezpiecznik)
     * @param callable|null $progress  opcjonalny callback progresu
     * @param int           $rowChunk  co ile wierszy podbijać progres (domyślnie 2000)
     */
    public static function enhance(
        string $path,
        int $maxRows = 100000,
        ?callable $progress = null,
        int $rowChunk = 2000
    ): void {
        if (!is_file($path)) {
            throw new RuntimeException('File not found: ' . $path);
        }

        // OPEN
        if ($progress) { $progress('Opening workbook', 0, null); }
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

        // Detect columns by header
        $imgCol = self::findHeaderColumn($sheet, 'image');
        $occCol = self::findHeaderColumn($sheet, 'occurrences');

        // Plan total steps for progress bar:
        // 1 (open) + 1 (base formatting) + (imgCol?1:0) + ceil((rows-1)/rowChunk) + autosizeCols + 1 (save)
        $colsTotal = Coordinate::columnIndexFromString($highestCol);
        $autoSizeCols = $colsTotal - ($imgCol ? 1 : 0);
        $rowChunks = max(0, (int)ceil(max(0, $highestRow - 1) / max(1, $rowChunk)));
        $totalSteps = 1 /*open*/ + 1 /*base*/ + ($imgCol ? 1 : 0) + $rowChunks + $autoSizeCols + 1 /*save*/;

        if ($progress) { $progress('Init progress', 0, $totalSteps); }

        // BASE FORMAT
        if ($progress) { $progress('Applying base formatting'); }
        $sheet->setAutoFilter("A1:{$highestCol}{$highestRow}");
        $sheet->freezePane('A2');
        $sheet->getStyle("A1:{$highestCol}{$highestRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        if ($occCol) {
            $sheet->getStyle("{$occCol}2:{$occCol}{$highestRow}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        // IMAGE COLUMN (width + min row height)
        if ($imgCol) {
            if ($progress) { $progress('Configuring image column'); }
            $sheet->getColumnDimension($imgCol)->setAutoSize(false);
            $sheet->getColumnDimension($imgCol)->setWidth(12.5); // ≈64px
            $advanced = 0;
            for ($r = 2; $r <= $highestRow; $r++) {
                $current = (float)$sheet->getRowDimension($r)->getRowHeight();
                if ($current < 48.0) { // ≈64px @96DPI
                    $sheet->getRowDimension($r)->setRowHeight(48.0);
                }
                if ($rowChunks > 0 && (($r - 1) % max(1, $rowChunk) === 0)) {
                    // Wykonaliśmy jedną porcję — przesuń progres
                    if ($progress) { $progress('Adjusting row heights', 1); }
                    $advanced++;
                }
            }
            if ($progress && $advanced < $rowChunks) {
                $progress('Adjusting row heights (finalize)', $rowChunks - $advanced);
            }
        }

        // Auto-size all columns except image
        for ($i = 1; $i <= $colsTotal; $i++) {
            $col = Coordinate::stringFromColumnIndex($i);
            if ($imgCol !== null && $col === $imgCol) {
                continue;
            }
            $sheet->getColumnDimension($col)->setAutoSize(true);
            if ($progress) { $progress("Autosizing {$col}", 1); }
        }

        // SAVE
        if ($progress) { $progress('Saving workbook'); }
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
            if (\mb_strtolower(\trim($val)) === \mb_strtolower($headerNameLower)) {
                return $col;
            }
        }
        return null;
    }
}
