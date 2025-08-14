<?php
declare(strict_types=1);

namespace MapMissingItems\Domain\Report;

use Generator;

/**
 * Merges two reports produced by map:gaps:scan with conflict resolution.
 *
 * Conflict rules (by id):
 *  - If only one row has non-empty "name" -> take that row.
 *  - If both have non-empty "name" -> keep BASE row.
 *  - If neither has "name" -> keep BASE row.
 *
 * Sorting:
 *  - 'occurrences' (default): by occurrences DESC, then id ASC.
 *  - 'id-asc': by id ASC.
 */
final class ReportMerger
{
    /** Expected output schema (without 'image' column – XLSX writer adds it itself) */
    public const FIELDS = [
        'id','occurrences','example_positions',
        'article','name','weight_attr','description_attr',
        'slotType_attr','weaponType_attr','armor_attr','defense_attr',
    ];

    /**
     * @param iterable<array<string, scalar|null>> $baseRows
     * @param iterable<array<string, scalar|null>> $otherRows
     * @param 'occurrences'|'id-asc' $sort
     * @return Generator<array<string, scalar|null>>
     */
    public function merge(iterable $baseRows, iterable $otherRows, string $sort = 'occurrences'): Generator
    {
        // 1) Normalize & index BASE by id
        $map = [];
        foreach ($baseRows as $row) {
            $norm = $this->normalizeRow($row);
            if ($norm['id'] === null) { continue; }
            $map[$norm['id']] = $norm;
        }

        // 2) Apply OTHER with conflict resolution
        foreach ($otherRows as $row) {
            $norm = $this->normalizeRow($row);
            $id = $norm['id'];
            if ($id === null) { continue; }

            if (!isset($map[$id])) {
                $map[$id] = $norm;
                continue;
            }

            // conflict — decide by "name" presence
            $base = $map[$id];
            $baseHasName  = $this->hasValue($base['name']);
            $otherHasName = $this->hasValue($norm['name']);

            if ($baseHasName && $otherHasName) {
                // keep base as per rule
                continue;
            }
            if ($otherHasName && !$baseHasName) {
                $map[$id] = $norm; // take "other"
                continue;
            }
            // neither has name -> keep base
        }

        // 3) Sort
        $rows = array_values($map);

        if ($sort === 'id-asc') {
            usort($rows, fn($a, $b) => $a['id'] <=> $b['id']);
        } else { // 'occurrences'
            usort($rows, function ($a, $b) {
                $cmp = ($b['occurrences'] <=> $a['occurrences']);
                return $cmp !== 0 ? $cmp : ($a['id'] <=> $b['id']);
            });
        }

        // 4) Yield in required order/shape
        foreach ($rows as $r) {
            $out = [];
            foreach (self::FIELDS as $k) {
                $out[$k] = $r[$k] ?? ($k === 'occurrences' ? 0 : '');
            }
            yield $out;
        }
    }

    /**
     * Normalize a raw row to expected columns & types
     *
     * @param array $row
     * @return array
     */
    private function normalizeRow(array $row): array
    {
        $id = isset($row['id']) ? (int)$row['id'] : null;
        if ($id !== null && $id <= 0) { $id = null; }

        $occ = 0;
        if (isset($row['occurrences'])) {
            $occ = (int)str_replace([' ', "\t", "\r", "\n"], '', (string)$row['occurrences']);
        }

        return [
            'id' => $id,
            'occurrences' => $occ,
            'example_positions' => isset($row['example_positions']) ? (string)$row['example_positions'] : '',
            'article' => isset($row['article']) ? trim((string)$row['article']) : '',
            'name' => isset($row['name']) ? trim((string)$row['name']) : '',
            'weight_attr' => isset($row['weight_attr']) ? (string)$row['weight_attr'] : '',
            'description_attr' => isset($row['description_attr']) ? (string)$row['description_attr'] : '',
            'slotType_attr' => isset($row['slotType_attr']) ? (string)$row['slotType_attr'] : '',
            'weaponType_attr' => isset($row['weaponType_attr']) ? (string)$row['weaponType_attr'] : '',
            'armor_attr' => isset($row['armor_attr']) ? (string)$row['armor_attr'] : '',
            'defense_attr' => isset($row['defense_attr']) ? (string)$row['defense_attr'] : '',
        ];
    }

    /**
     * @param string|null $s
     * @return bool
     */
    private function hasValue(?string $s): bool
    {
        return $s !== null && trim($s) !== '';
    }
}
