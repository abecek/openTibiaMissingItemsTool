<?php
declare(strict_types=1);

namespace MapMissingItems\Domain\MapScan;

use Generator;

/**
 * Iterates NDJSON file line-by-line yielding ['id'=>int,'x'=>int,'y'=>int,'z'=>int].
 */
final class MapNdjsonReader
{
    /**
     * @param string $ndjsonPath
     * @return Generator<int, array{id:int,x:int,y:int,z:int}>
     */
    public function iterateItems(string $ndjsonPath): Generator
    {
        $file = new \SplFileObject($ndjsonPath, 'r');
        while (!$file->eof()) {
            $line = $file->fgets();
            if ($line === '' || $line === false) continue;
            $line = trim($line);
            if ($line === '') continue;
            $row = json_decode($line, true);
            if (!is_array($row) || !isset($row['id'],$row['x'],$row['y'],$row['z'])) continue;
            yield ['id'=>(int)$row['id'], 'x'=>(int)$row['x'], 'y'=>(int)$row['y'], 'z'=>(int)$row['z']];
        }
    }
}
