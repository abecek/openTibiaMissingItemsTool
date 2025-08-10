<?php
declare(strict_types=1);

namespace EK\MapItemGaps\Infrastructure\Output;

use League\Csv\Writer;

final class CsvWriter implements ResultWriterInterface
{
    /** @param \Generator $rows */
    public function write(\Generator $rows, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $csv = Writer::createFromPath($path, 'w');
        // Header
        $csv->insertOne([
            'id','occurrences','example_positions',
            'article','name','weight_attr','description_attr',
            'slotType_attr','weaponType_attr','armor_attr','defense_attr'
        ]);
        // Rows
        foreach ($rows as $row) {
            $csv->insertOne([
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
    }
}
