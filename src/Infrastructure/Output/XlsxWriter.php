<?php
declare(strict_types=1);

namespace EK\MapItemGaps\Infrastructure\Output;

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Writer\Helper\StyleHelper;
use OpenSpout\Writer\XLSX\Options;

final class XlsxWriter implements ResultWriterInterface
{
    /** @param \Generator $rows */
    public function write(\Generator $rows, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $options = new Options();
        $writer = new Writer($options);
        $writer->openToFile($path);

        // Header
        $writer->addRow(['id','occurrences','example_positions','article','name','weight_attr','description_attr','slotType_attr','weaponType_attr','armor_attr','defense_attr']);

        foreach ($rows as $row) {
            $writer->addRow([
                $row['id'],
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
            ]);
        }

        $writer->close();
    }
}
