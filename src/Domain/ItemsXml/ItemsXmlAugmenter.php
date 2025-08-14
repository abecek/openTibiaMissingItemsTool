<?php
declare(strict_types=1);

namespace MapMissingItems\Domain\ItemsXml;

use DOMDocument;
use DOMElement;
use MapMissingItems\Domain\Report\ReportReader;
use RuntimeException;
use DOMException;

/**
 * Service that appends new <item> entries to items.xml based on a CSV/XLSX report.
 * - Reads report rows (id, article?, name) — name is required, article optional.
 * - Skips IDs already covered by existing <item id="..."/> or <item fromid=".." toid=".."/>.
 * - Sorts ascending by id and groups consecutive ids with identical (article, name) to {fromid,toid}.
 * - Appends a clearly marked block at the end of <items>.
 *
 * Progress callback signature (optional): function(string $message): void
 */
final class ItemsXmlAugmenter
{
    /**
     * @param array{
     *   itemsXmlPath:string,
     *   outputXmlPath: string|null,
     *   reportPath:string,
     *   sheetIndex:int,
     *   csvDelimiter:string,
     *   rowChunk:int,
     *   dryRun:bool,
     *   backup:bool
     * } $opts
     * @param callable|null $progress
     * @return array{
     *   workingXml:string,
     *   consideredRows:int,
     *   candidateIds:int,
     *   groups:int,
     *   appendedEntries:int,
     *   dryRun:bool
     * }
     * @throws DOMException
     */
    public function augment(array $opts, ?callable $progress = null): array
    {
        $itemsXmlPath = $opts['itemsXmlPath'];
        $outputXmlPath = $opts['outputXmlPath']; // if provided, copy input to this file and work on the copy
        $reportPath  = $opts['reportPath'];
        $sheetIndex  = (int)$opts['sheetIndex'];
        $csvDelimiter = (string)$opts['csvDelimiter'];
        $rowChunk = max(1, (int)$opts['rowChunk']);
        $dryRun   = (bool)$opts['dryRun'];
        $backup   = (bool)$opts['backup'];

        if (!is_file($itemsXmlPath)) {
            throw new RuntimeException('items.xml not found: ' . $itemsXmlPath);
        }
        if (!is_file($reportPath)) {
            throw new RuntimeException('Report not found: ' . $reportPath);
        }

        // Determine working XML path: either the source file (in-place) or a copy at --output
        $workingXml = $itemsXmlPath;
        if ($outputXmlPath !== null && $outputXmlPath !== '') {
            $outDir = dirname($outputXmlPath);
            if (!is_dir($outDir)) {
                @mkdir($outDir, 0775, true);
            }
            if (!copy($itemsXmlPath, $outputXmlPath)) {
                throw new RuntimeException('Failed to copy items.xml to output: ' . $outputXmlPath);
            }
            $workingXml = $outputXmlPath;
            if ($progress) { $progress('Copied items.xml to output: ' . $workingXml); }
        }

        // [1] Load XML (working file)
        if ($progress) { $progress('[1/4] Loading items.xml'); }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true; // preserve existing formatting/comments
        $dom->formatOutput = true;
        if (!$dom->load($workingXml)) {
            throw new RuntimeException('Failed to load XML: ' . $workingXml);
        }
        $root = $dom->getElementsByTagName('items')->item(0);
        if (!$root instanceof DOMElement) {
            throw new RuntimeException('<items> root not found in XML.');
        }

        // Build coverage index
        [$coveredIds, $coveredRanges] = $this->buildCoverageIndex($root);

        // [2] Read report -> triplets
        if ($progress) { $progress('[2/4] Reading report: ' . $reportPath); }
        $countTotal = 0;
        $triplets = [];
        $reportReader = new ReportReader();
        foreach ($reportReader->read($reportPath, $sheetIndex, $csvDelimiter) as $row) {
            $countTotal++;
            if (($countTotal % $rowChunk) === 0 && $progress) {
                $progress("  scanned {$countTotal} rows…");
            }

            $id = isset($row['id']) ? (int)$row['id'] : 0;
            if ($id <= 0) { continue; }

            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') { continue; }

            $article = trim((string)($row['article'] ?? ''));

            if ($this->isCovered($id, $coveredIds, $coveredRanges)) {
                continue;
            }

            $triplets[] = ['id' => $id, 'article' => $article, 'name' => $name];
        }
        if ($progress) { $progress("  scanned total {$countTotal} rows."); }

        if (!$triplets) {
            if ($progress) { $progress('No candidates to append. Nothing to do.'); }
            return [
                'workingXml' => $workingXml,
                'consideredRows' => $countTotal,
                'candidateIds' => 0,
                'groups' => 0,
                'appendedEntries' => 0,
                'dryRun' => $dryRun
            ];
        }

        // [3] Sort & group
        if ($progress) { $progress('[3/4] Grouping consecutive IDs with equal (article, name)'); }
        usort($triplets, static fn($a, $b) => $a['id'] <=> $b['id']);
        $groups = $this->groupTriplets($triplets);

        if ($dryRun) {
            // only preview — return summary, do not modify file
            if ($progress) {
                $progress(sprintf('Preview (dry-run): %d group(s), %d candidate ID(s).', count($groups), count($triplets)));
            }
            return [
                'workingXml' => $workingXml,
                'consideredRows' => $countTotal,
                'candidateIds' => count($triplets),
                'groups' => count($groups),
                'appendedEntries' => 0,
                'dryRun' => true
            ];
        }

        // [4] Append to XML
        if ($progress) { $progress('[4/4] Appending groups to XML'); }

        if ($backup) {
            $backupPath = $workingXml . '.bak.' . date('Ymd_His');
            if (!copy($workingXml, $backupPath)) {
                throw new RuntimeException('Failed to create backup: ' . $backupPath);
            }
            if ($progress) { $progress('  backup created: ' . $backupPath); }
        }

        $this->appendBlock($dom, $root, $groups);
        $dom->save($workingXml);

        return [
            'workingXml' => $workingXml,
            'consideredRows' => $countTotal,
            'candidateIds' => count($triplets),
            'groups' => count($groups),
            'appendedEntries' => array_reduce($groups, fn($c, $g) => $c + ($g['type'] === 'range' ? 2 : 1), 0),
            'dryRun' => false
        ];
    }

    /**
     * Build coverage from existing <item> nodes.
     *
     * @param DOMElement $root
     * @return array[]
     */
    private function buildCoverageIndex(DOMElement $root): array
    {
        $coveredIds = [];
        $coveredRanges = [];

        /** @var DOMElement $el */
        foreach ($root->getElementsByTagName('item') as $el) {
            if ($el->hasAttribute('id')) {
                $id = (int)$el->getAttribute('id');
                if ($id > 0) { $coveredIds[$id] = true; }
            } elseif ($el->hasAttribute('fromid') && $el->hasAttribute('toid')) {
                $from = (int)$el->getAttribute('fromid');
                $to   = (int)$el->getAttribute('toid');
                if ($from > 0 && $to >= $from) {
                    $coveredRanges[] = [$from, $to];
                }
            }
        }

        if ($coveredRanges) {
            usort($coveredRanges, static fn($a, $b) => $a[0] <=> $b[0]);
            // merge overlaps / adjacents
            $merged = [];
            foreach ($coveredRanges as [$f, $t]) {
                if (!$merged || $f > $merged[count($merged)-1][1] + 1) {
                    $merged[] = [$f, $t];
                } else {
                    $merged[count($merged)-1][1] = max($merged[count($merged)-1][1], $t);
                }
            }
            $coveredRanges = $merged;
        }

        return [$coveredIds, $coveredRanges];
    }

    /**
     * @param int $id
     * @param array $coveredIds
     * @param array $coveredRanges
     * @return bool
     */
    private function isCovered(int $id, array $coveredIds, array $coveredRanges): bool
    {
        if (isset($coveredIds[$id])) {
            return true;
        }
        // binary search over ranges
        $lo = 0; $hi = count($coveredRanges) - 1;
        while ($lo <= $hi) {
            $mid = (int)(($lo + $hi) / 2);
            [$from, $to] = $coveredRanges[$mid];
            if ($id < $from) { $hi = $mid - 1; }
            elseif ($id > $to) { $lo = $mid + 1; }
            else { return true; }
        }
        return false;
    }

    /**
     * Group sorted triplets (id, article, name) into single or range entries.
     *
     * @param array<int, array{id:int, article:string, name:string}> $triplets
     * @return array<int, array{
     *   type: 'single'|'range',
     *   id?: int,
     *   from?: int, to?: int,
     *   article: string, name: string
     * }>
     */
    private function groupTriplets(array $triplets): array
    {
        $groups = [];
        $n = count($triplets);
        $i = 0;
        while ($i < $n) {
            $start = $i;
            $article = $triplets[$i]['article'];
            $name    = $triplets[$i]['name'];
            $prevId  = $triplets[$i]['id'];

            $j = $i + 1;
            while ($j < $n) {
                $t = $triplets[$j];
                if ($t['article'] !== $article || $t['name'] !== $name) { break; }
                if ($t['id'] !== $prevId + 1) { break; }
                $prevId = $t['id'];
                $j++;
            }

            if ($j - $start >= 2) {
                $groups[] = [
                    'type' => 'range',
                    'from' => $triplets[$start]['id'],
                    'to'   => $triplets[$j - 1]['id'],
                    'article' => $article,
                    'name'    => $name,
                ];
            } else {
                $groups[] = [
                    'type' => 'single',
                    'id'   => $triplets[$start]['id'],
                    'article' => $article,
                    'name'    => $name,
                ];
            }
            $i = $j;
        }
        return $groups;
    }

    /**
     * Append a separated block with new items at the end of <items>.
     *
     * @param DOMDocument $dom
     * @param DOMElement $root
     * @param array $groups
     * @return void
     * @throws DOMException
     */
    private function appendBlock(DOMDocument $dom, DOMElement $root, array $groups): void
    {
        // Sort groups by min id to ensure ascending order within the appended block
        usort($groups, static function ($a, $b) {
            $aMin = $a['type'] === 'range' ? $a['from'] : $a['id'];
            $bMin = $b['type'] === 'range' ? $b['from'] : $b['id'];
            return $aMin <=> $bMin;
        });

        $root->appendChild($dom->createTextNode("\n\t"));
        $root->appendChild($dom->createComment(' BEGIN auto-appended by items:xml:augment '));
        $root->appendChild($dom->createTextNode("\n"));

        foreach ($groups as $g) {
            $root->appendChild($dom->createTextNode("\t"));
            $item = $dom->createElement('item');

            if ($g['type'] === 'single') {
                $item->setAttribute('id', (string)$g['id']);
            } else {
                $item->setAttribute('fromid', (string)$g['from']);
                $item->setAttribute('toid', (string)$g['to']);
            }

            if ($g['article'] !== '') {
                $item->setAttribute('article', $g['article']);
            }
            $item->setAttribute('name', $g['name']);

            $root->appendChild($item);
            $root->appendChild($dom->createTextNode("\n"));
        }

        $root->appendChild($dom->createTextNode("\t"));
        $root->appendChild($dom->createComment(' END auto-appended by items:xml:augment '));
        $root->appendChild($dom->createTextNode("\n"));
    }
}
