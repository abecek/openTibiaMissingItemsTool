<?php
declare(strict_types=1);

namespace EK\MapItemGaps\Domain\MapScan;

use EK\MapItemGaps\Domain\ItemsXml\ItemsIndex;

final class ItemOccurrenceCounter
{
    /** @var array<int,int> */
    private array $counts = [];
    /** @var array<int,array{positions: string[]}> */
    private array $meta = [];

    /**
     * @param array<int, array{id:int,x:int,y:int,z:int}> $items
     * @param callable $tick Called every ~10k items
     */
    public function count(array $items, ItemsIndex $index, callable $tick): void
    {
        $n = 0;
        foreach ($items as $rec) {
            $id = (int)$rec['id'];
            if (!$index->exists($id)) {
                $this->counts[$id] = ($this->counts[$id] ?? 0) + 1;
                if (!isset($this->meta[$id])) {
                    $this->meta[$id] = ['positions' => []];
                }
                if (count($this->meta[$id]['positions']) < 5) {
                    $this->meta[$id]['positions'][] = "{$rec['x']}:{$rec['y']}:{$rec['z']}";
                }
            }
            if ((++$n % 10000) === 0) {
                $tick($n);
            }
        }
    }

    /**
     * @return \Generator<int, array{
     *   id:int, occurrences:int, example_positions:string,
     *   article:string, name:string, weight_attr:string, description_attr:string,
     *   slotType_attr:string, weaponType_attr:string, armor_attr:string, defense_attr:string
     * }>
     */
    public function result(): \Generator
    {
        arsort($this->counts, SORT_NUMERIC);
        foreach ($this->counts as $id => $occ) {
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
