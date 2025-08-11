<?php
declare(strict_types=1);

namespace MapMissingItems\Infrastructure\Output;

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Common\Entity\Row;
use Generator;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Writer\Exception\WriterNotOpenedException;

final class XlsxWriter implements ResultWriterInterface
{
    /**
     * @param Generator $rows
     * @param string $path
     * @return void
     * @throws IOException
     * @throws WriterNotOpenedException
     */
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
        $writer->addRow(Row::fromValues([
            'id','occurrences','example_positions',
            'article','name','weight_attr','description_attr',
            'slotType_attr','weaponType_attr','armor_attr','defense_attr'
        ]));

        // Data
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues([
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
            ]));
        }

        $writer->close();
    }
}
