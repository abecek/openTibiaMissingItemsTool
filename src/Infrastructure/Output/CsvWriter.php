<?php
declare(strict_types=1);

namespace MapMissingItems\Infrastructure\Output;

use MapItemGaps\Dict\IdExceptionListInterface;
use Generator;
use League\Csv\Writer;
use League\Csv\CannotInsertRecord;
use League\Csv\Exception;
use League\Csv\UnavailableStream;

final class CsvWriter implements ResultWriterInterface
{
    /**
     * @param Generator $rows
     * @param string $path
     * @return void
     * @throws CannotInsertRecord
     * @throws Exception
     * @throws UnavailableStream
     */
    public function write(Generator $rows, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $csv = Writer::createFromPath($path, 'w');
        $csv->insertOne([
            'id','occurrences','example_positions',
            'article','name','weight_attr','description_attr',
            'slotType_attr','weaponType_attr','armor_attr','defense_attr'
        ]);
        foreach ($rows as $row) {
            if (in_array((int) $row['id'], IdExceptionListInterface::IDS_TO_SKIP)) continue;
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
