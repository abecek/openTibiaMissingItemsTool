<?php
declare(strict_types=1);

namespace MapMissingItems\Infrastructure\Output;

use MapMissingItems\Dict\IdExceptionListInterface;
use OpenSpout\Writer\XLSX\Writer as SpoutWriter;
use OpenSpout\Writer\XLSX\Options as SpoutOptions;
use OpenSpout\Common\Entity\Row as SpoutRow;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Generator;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Writer\Exception\WriterNotOpenedException;

/**
 * Writes XLSX via OpenSpout (streaming) and then injects PNG icons into the "image" column using PhpSpreadsheet.
 *
 * write($rows, $path, $imageDir):
 *  - $imageDir: directory with PNG files named by item id (e.g., 2261.png). If null, images are skipped.
 *  - "image" is the SECOND column in the sheet.
 */
final class XlsxWriter implements ResultWriterInterface
{
    /**
     * @param Generator $rows
     * @param string $path
     * @param string|null $imageDir Directory containing "<id>.png" icons; pass null to skip images.
     * @return void
     * @throws IOException
     * @throws WriterNotOpenedException
     */
    public function write(Generator $rows, string $path, ?string $imageDir = null): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // 1) Stream data with OpenSpout (low memory)
        $options = new SpoutOptions();
        $writer  = new SpoutWriter($options);
        $writer->openToFile($path);

        // Header — "image" is SECOND
        $header = [
            'id',
            'image',
            'occurrences',
            'example_positions',
            'article','name','weight_attr','description_attr',
            'slotType_attr','weaponType_attr','armor_attr','defense_attr',
        ];
        $writer->addRow(SpoutRow::fromValues($header));

        // track rows that should get an image (row index => png path); first data row = 2
        $rowIndex  = 2;
        $imagesMap = [];

        foreach ($rows as $row) {
            if (in_array((int) $row['id'], IdExceptionListInterface::IDS_TO_SKIP)) continue;
            // SECOND cell is the placeholder for image
            $writer->addRow(SpoutRow::fromValues([
                $row['id'],
                '', // image placeholder
                $row['occurrences'],
                $row['example_positions'],
                $row['article'],
                $row['name'],
                $row['weight_attr'],
                $row['description_attr'],
                $row['slotType_attr'],
                $row['weaponType_attr'],
                $row['armor_attr'],
                $row['defense_attr'],
            ]));

            if ($imageDir !== null) {
                $png = rtrim($imageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ((string)$row['id']) . '.png';
                if (is_file($png)) {
                    $imagesMap[$rowIndex] = $png;
                }
            }
            $rowIndex++;
        }

        $writer->close();

        // 2) Inject PNG icons via PhpSpreadsheet
        if ($imageDir !== null && $imagesMap !== []) {
            $this->injectImages($path, $imagesMap);
        }
    }

    /**
     * Put images into the "image" column, set min row height (~64px) and a fixed column width (~64px visual).
     *
     * @param string $xlsxPath
     * @param array<int,string> $rowToImagePath map: rowIndex => absolute PNG path
     * @return void
     */
    private function injectImages(string $xlsxPath, array $rowToImagePath): void
    {
        $spreadsheet = IOFactory::load($xlsxPath);
        $sheet = $spreadsheet->getActiveSheet();

        // Locate "image" column by header text
        $imageCol = $this->findHeaderColumn($sheet, 'image');
        if ($imageCol === null) {
            // Fallback to column B (second), but header should exist
            $imageCol = 'B';
        }

        // Set image column width to ~64px visual (about 12.5 "chars")
        $sheet->getColumnDimension($imageCol)->setAutoSize(false);
        $sheet->getColumnDimension($imageCol)->setWidth(12.5);

        foreach ($rowToImagePath as $rowIdx => $pngPath) {
            if (!is_file($pngPath)) {
                continue;
            }
            $targetPx = 64;
            $imgSize = @getimagesize($pngPath);
            $imgHeightPx = $imgSize ? min($targetPx, (int)$imgSize[1]) : $targetPx;

            $drawing = new Drawing();
            $drawing->setName('item-'.$rowIdx);
            $drawing->setDescription('item-'.$rowIdx);
            $drawing->setPath($pngPath);
            $drawing->setHeight($imgHeightPx); // width scales proportionally
            $drawing->setCoordinates($imageCol . (string)$rowIdx);
            $drawing->setOffsetX(2);
            $drawing->setOffsetY(2);
            $drawing->setWorksheet($sheet);

            // Ensure min row height (~64px -> ~48pt)
            $rowHeightPt = max((float)$sheet->getRowDimension($rowIdx)->getRowHeight(), $imgHeightPx * 0.75);
            //$rowHeightPt = $imgHeightPx + 4;
            $sheet->getRowDimension($rowIdx)->setRowHeight($rowHeightPt);
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($xlsxPath);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    /**
     * @param Worksheet $sheet
     * @param string $headerNameLower
     * @return string|null
     */
    private function findHeaderColumn(Worksheet $sheet, string $headerNameLower): ?string
    {
        $highestCol = $sheet->getHighestDataColumn(); // e.g. 'L' or 'AA'
        $maxIndex   = Coordinate::columnIndexFromString($highestCol);
        for ($i = 1; $i <= $maxIndex; $i++) {
            $col = Coordinate::stringFromColumnIndex($i);
            $val = (string)$sheet->getCell($col . '1')->getValue();
            if (mb_strtolower(\trim($val)) === mb_strtolower($headerNameLower)) {
                return $col;
            }
        }
        return null;
    }
}