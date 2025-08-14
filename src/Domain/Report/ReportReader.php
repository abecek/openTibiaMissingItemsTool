<?php
declare(strict_types=1);

namespace MapMissingItems\Domain\Report;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Generator;
use SplFileObject;
use RuntimeException;

final class ReportReader
{
    /**
     * Iterate report rows (XLSX/CSV) as assoc arrays keyed by lowercased headers.
     * Supported headers: id, occurrences, example_positions, article, name, weight_attr,
     * description_attr, slotType_attr, weaponType_attr, armor_attr, defense_attr
     *
     * @param string $path
     * @param int $sheetIndex
     * @param string $csvDelimiter
     * @return Generator<array<string, scalar|null>>
     */
    public function read(string $path, int $sheetIndex = 0, string $csvDelimiter = ','): Generator
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'xlsx') {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getSheet($sheetIndex);

            $highestRow = $sheet->getHighestDataRow();
            $highestCol = $sheet->getHighestDataColumn();
            $highestColIndex = Coordinate::columnIndexFromString($highestCol);

            // Header row (1)
            $headers = [];
            for ($c = 1; $c <= $highestColIndex; $c++) {
                $col = Coordinate::stringFromColumnIndex($c);
                $headers[$c] = strtolower(trim((string)$sheet->getCell($col . '1')->getValue()));
            }

            for ($r = 2; $r <= $highestRow; $r++) {
                $assoc = [];
                for ($c = 1; $c <= $highestColIndex; $c++) {
                    $col = Coordinate::stringFromColumnIndex($c);
                    $key = $headers[$c] ?: ('col'.$c);
                    $val = $sheet->getCell($col . (string)$r)->getValue();
                    $assoc[$key] = is_scalar($val) ? $val : (string)$val;
                }
                yield $assoc;
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            return;
        }

        if ($ext === 'csv') {
            $file = new SplFileObject($path, 'r');
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
            $file->setCsvControl($csvDelimiter);

            $headers = null;
            foreach ($file as $row) {
                if ($row === [null] || $row === false) { continue; }
                if ($headers === null) {
                    $headers = array_map(fn($h) => strtolower(trim((string)$h)), $row);
                    continue;
                }
                $assoc = [];
                foreach ($row as $i => $v) {
                    $key = $headers[$i] ?? ('col'.$i);
                    $assoc[$key] = is_scalar($v) ? $v : (string)$v;
                }
                yield $assoc;
            }
            return;
        }

        throw new RuntimeException('Unsupported report extension: ' . $ext);
    }
}
