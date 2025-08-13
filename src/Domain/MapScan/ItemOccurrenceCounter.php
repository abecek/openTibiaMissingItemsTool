<?php
declare(strict_types=1);

namespace MapMissingItems\Domain\MapScan;

use MapMissingItems\Domain\ItemsXml\ItemsIndex;
use Generator;

final class ItemOccurrenceCounter
{
    /** @var array<int,int> */
    private array $counts = [];
    /** @var array<int,array{positions: string[]}> */
    private array $meta = [];

    /**
     * @param iterable<array{id:int,x:int,y:int,z:int}> $items
     * @param ItemsIndex $index
     * @param ?int $sampleLimit If set, process only first N records
     * @param callable $tick Called roughly every 10k records
     */
    public function count(iterable $items, ItemsIndex $index, ?int $sampleLimit, callable $tick): void
    {
        $n = 0;
        foreach ($items as $rec) {
            $id = (int)$rec['id'];
            if (!$index->exists($id)) {
                $this->counts[$id] = ($this->counts[$id] ?? 0) + 1;
                if (!isset($this->meta[$id])) $this->meta[$id] = ['positions' => []];
                if (count($this->meta[$id]['positions']) < 5) {
                    $this->meta[$id]['positions'][] = "{$rec['x']}:{$rec['y']}:{$rec['z']}";
                }
            }
            $n++;
            if (($n % 10000) === 0) $tick($n);
            if ($sampleLimit !== null && $n >= $sampleLimit) break;
        }
    }

    /**
     * Generates rows for writers in requested order.
     *
     * @param 'occurrences'|'id-asc' $sort
     *   - 'occurrences' (default): by occurrences DESC
     *   - 'id-asc': by id ASC
     *
     * @return Generator<int, array{
     *   id:int, occurrences:int, example_positions:string,
     *   article:string, name:string, weight_attr:string, description_attr:string,
     *   slotType_attr:string, weaponType_attr:string, armor_attr:string, defense_attr:string
     * }>
     */
    public function result(string $sort = 'occurrences'): Generator
    {
        // Build ordered list of IDs without mutating internal state
        $orderedIds = array_keys($this->counts);

        if ($sort === 'id-asc') {
            sort($orderedIds, SORT_NUMERIC);
        } else { // 'occurrences'
            usort($orderedIds, function (int $a, int $b): int {
                return $this->counts[$b] <=> $this->counts[$a];
            });
        }

        foreach ($orderedIds as $id) {
            $occ = $this->counts[$id];
            $examples = isset($this->meta[$id]) ? implode(',', $this->meta[$id]['positions']) : '';
            yield [
                'id' => $id,
                'occurrences' => $occ,
                'example_positions' => $examples,
                'article' => '',
                'name' => '',
                'weight_attr' => '',
                'description_attr' => '',
                'slotType_attr' => '',
                'weaponType_attr' => '',
                'armor_attr' => '',
                'defense_attr' => '',
            ];
        }
    }
}
