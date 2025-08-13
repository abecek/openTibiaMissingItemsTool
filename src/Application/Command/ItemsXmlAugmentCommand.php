<?php
declare(strict_types=1);

namespace MapMissingItems\Application\Command;

use DOMDocument;
use DOMElement;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SplFileObject;
use Generator;

/**
 * Append new <item> entries to items.xml based on a CSV/XLSX report.
 *
 * Expected report columns:
 *   - id (int, required)
 *   - article (string, optional)
 *   - name (string, required)
 *
 * Only rows with a non-empty "name" are considered.
 * Rows whose IDs are already covered by existing <item id="..."/> or
 * by any <item fromid="X" toid="Y"/> in items.xml are skipped.
 *
 * Consecutive IDs that share the same (article, name) are merged into
 * a range entry: <item fromid=".." toid=".." article=".." name=".."/>
 */
#[AsCommand(
    name: 'items:xml:augment',
    description: 'Append new <item> entries to items.xml from a CSV/XLSX report (article & name).'
)]
final class ItemsXmlAugmentCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'items-xml',
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to items.xml to modify',
                'data/input/items.xml'
            )
            ->addOption(
                'report',
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to the filled report (xlsx or csv). Default: data/output/missing-items.xlsx if exists, otherwise data/output/missing-items.csv',
                ''
            )
            ->addOption(
                'csv-delimiter',
                null,
                InputOption::VALUE_OPTIONAL,
                'CSV delimiter',
                ','
            )
            ->addOption(
                'no-backup',
                null,
                InputOption::VALUE_NONE,
                'Do not create a timestamped backup of items.xml before modifying'
            )
            ->addOption(
                'sheet',
                null,
                InputOption::VALUE_OPTIONAL,
                'Sheet index (0-based) for XLSX reading',
                '0'
            )
            ->addOption(
                'row-chunk',
                null,
                InputOption::VALUE_OPTIONAL,
                'Progress granularity for scanning report rows',
                '5000'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview changes without writing to items.xml'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $itemsXmlPath = (string)$input->getOption('items-xml');
        $reportPath = (string)$input->getOption('report');
        $csvDelimiter = (string)$input->getOption('csv-delimiter') ?: ',';
        $noBackup = (bool)$input->getOption('no-backup');
        $sheetIndex = max(0, (int)$input->getOption('sheet'));
        $rowChunk = max(1, (int)$input->getOption('row-chunk'));
        $dryRun = (bool)$input->getOption('dry-run');

        if (!is_file($itemsXmlPath)) {
            throw new RuntimeException('items.xml not found: ' . $itemsXmlPath);
        }

        if ($reportPath === '') {
            $xlsxDefault = 'data/output/missing-items.xlsx';
            $csvDefault  = 'data/output/missing-items.csv';
            if (is_file($xlsxDefault))      { $reportPath = $xlsxDefault; }
            elseif (is_file($csvDefault))   { $reportPath = $csvDefault; }
            else {
                throw new RuntimeException('Report file not specified and no default report found in data/output/.');
            }
        }

        if (!is_file($reportPath)) {
            throw new RuntimeException('Report not found: ' . $reportPath);
        }

        // Step 1: Load items.xml to DOM, prepare index of covered IDs/ranges
        $output->writeln('[1/5] Loading items.xml');
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;   // keep existing formatting/comments
        $dom->formatOutput = true;
        if (!$dom->load($itemsXmlPath)) {
            throw new RuntimeException('Failed to load XML: ' . $itemsXmlPath);
        }
        $root = $dom->getElementsByTagName('items')->item(0);
        if (!$root instanceof DOMElement) {
            throw new RuntimeException('<items> root not found in XML.');
        }

        [$coveredIds, $coveredRanges] = $this->buildCoverageIndex($root);

        // Step 2: Read report (CSV/XLSX)
        $output->writeln('[2/5] Reading report: ' . $reportPath);
        $rowsGen = $this->readReport($reportPath, $sheetIndex, $csvDelimiter);

        // We’ll first buffer minimal triplets (id, article, name) to be able to sort/group.
        $triplets = [];
        $countTotal = 0;
        $bar = new ProgressBar($output);
        $bar->setFormat('  %current% rows scanned ...');
        $bar->start();

        foreach ($rowsGen as $row) {
            $countTotal++;
            if ($countTotal % $rowChunk === 0) { $bar->advance($rowChunk); }

            $id = isset($row['id']) ? (int)$row['id'] : 0;
            if ($id <= 0) { continue; }

            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') { continue; }

            $article = trim((string)($row['article'] ?? ''));

            // Skip ids already covered by existing items/ranges
            if ($this->isCovered($id, $coveredIds, $coveredRanges)) {
                continue;
            }

            $triplets[] = ['id' => $id, 'article' => $article, 'name' => $name];
        }
        // finalize progress display
        $remaining = $countTotal % $rowChunk;
        if ($remaining > 0) { $bar->advance($remaining); }
        $bar->finish();
        $output->writeln('');
        $output->writeln(sprintf('  - Considered %d rows, %d candidate IDs after skipping covered ones.', $countTotal, count($triplets)));

        if (!$triplets) {
            $output->writeln('<comment>No candidates to append. Nothing to do.</comment>');
            return Command::SUCCESS;
        }

        // Step 3: Sort by id, group consecutive by (article,name)
        $output->writeln('[3/5] Grouping consecutive IDs with same (article,name)');
        usort($triplets, static fn($a, $b) => $a['id'] <=> $b['id']);
        $groups = $this->groupTriplets($triplets);

        if ($dryRun) {
            $output->writeln('[4/5] Preview (dry-run) — nothing will be written');
            foreach ($groups as $g) {
                if ($g['type'] === 'single') {
                    $output->writeln(sprintf('  <item id="%d"%s name="%s"/>',
                        $g['id'],
                        $g['article'] !== '' ? ' article="'.$g['article'].'"' : '',
                        $g['name']
                    ));
                } else {
                    $output->writeln(sprintf('  <item fromid="%d" toid="%d"%s name="%s"/>',
                        $g['from'], $g['to'],
                        $g['article'] !== '' ? ' article="'.$g['article'].'"' : '',
                        $g['name']
                    ));
                }
            }
            $output->writeln('[5/5] Done (dry-run)');
            return Command::SUCCESS;
        }

        // Step 4: Backup (optional) & append to XML
        $output->writeln('[4/5] Appending new items to XML');
        if (!$noBackup) {
            $backup = $itemsXmlPath . '.bak.' . date('Ymd_His');
            if (!copy($itemsXmlPath, $backup)) {
                throw new RuntimeException('Failed to create backup: ' . $backup);
            }
            $output->writeln('  - Backup created: ' . $backup);
        }

        $this->appendBlock($dom, $root, $groups);
        $dom->save($itemsXmlPath);

        // Step 5: Summary
        $output->writeln('[5/5] Done');
        $appendedCount = array_reduce($groups, fn($c, $g) => $c + ($g['type'] === 'range' ? 2 : 1), 0);
        $output->writeln(sprintf(
            '<info>Appended %d entries (%d groups). IDs: %d unique.</info>',
            $appendedCount, count($groups), count($triplets)
        ));

        return Command::SUCCESS;
    }

    /**
     * Build coverage index from existing <item> nodes:
     * - $coveredIds: set<int, true>
     * - $coveredRanges: list of [from, to]
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
        // optional normalization of ranges
        if ($coveredRanges) {
            usort($coveredRanges, static fn($a, $b) => $a[0] <=> $b[0]);
            // merge overlaps
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
            $current = $triplets[$i];
            $article = $current['article'];
            $name = $current['name'];
            $prevId = $current['id'];

            $j = $i + 1;
            while ($j < $n) {
                $t = $triplets[$j];
                if ($t['article'] !== $article || $t['name'] !== $name) {
                    break;
                }
                if ($t['id'] !== $prevId + 1) {
                    break;
                }
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
     * Append a nicely separated block with new items, sorted by id/range start.
     */
    private function appendBlock(DOMDocument $dom, DOMElement $root, array $groups): void
    {
        // Sort groups by their min id to be safe
        usort($groups, static function ($a, $b) {
            $aMin = $a['type'] === 'range' ? $a['from'] : $a['id'];
            $bMin = $b['type'] === 'range' ? $b['from'] : $b['id'];
            return $aMin <=> $bMin;
        });

        $root->appendChild($dom->createTextNode("\n\t"));
        $root->appendChild($dom->createComment(' --- BEGIN auto-appended by items:xml:augment --- '));
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

            // self-closing like <item id=".." name=".."/>
            $root->appendChild($item);
            $root->appendChild($dom->createTextNode("\n"));
        }

        $root->appendChild($dom->createTextNode("\t"));
        $root->appendChild($dom->createComment(' --- END auto-appended by items:xml:augment --- '));
        $root->appendChild($dom->createTextNode("\n"));
    }

    /**
     * Read report rows from XLSX/CSV. Yields associative arrays keyed by header names (lowercased).
     * Implementation uses PhpSpreadsheet for XLSX and SplFileObject for CSV (no OpenSpout needed).
     * @param string $path
     * @param int $sheetIndex
     * @return Generator<array<string, scalar|null>>
     */
    private function readReport(string $path, int $sheetIndex = 0, string $csvDelimiter = ','): Generator
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'xlsx') {
            // PhpSpreadsheet
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getSheet($sheetIndex);

            $highestRow = $sheet->getHighestDataRow();
            $highestCol = $sheet->getHighestDataColumn();
            $highestColIndex = Coordinate::columnIndexFromString($highestCol);

            // Header (row 1)
            $headers = [];
            for ($c = 1; $c <= $highestColIndex; $c++) {
                $colLetter = Coordinate::stringFromColumnIndex($c);
                $headers[$c] = strtolower(trim((string)$sheet->getCell($colLetter . '1')->getValue()));
            }

            // Data rows
            for ($r = 2; $r <= $highestRow; $r++) {
                $assoc = [];
                for ($c = 1; $c <= $highestColIndex; $c++) {
                    $colLetter = Coordinate::stringFromColumnIndex($c);
                    $key = $headers[$c] ?: ('col'.$c);
                    $val = $sheet->getCell($colLetter . (string)$r)->getValue(); // brak formuł -> getValue
                    $assoc[$key] = is_scalar($val) ? $val : (string)$val;
                }
                yield $assoc;
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            return;
        }

        if ($ext === 'csv') {
            // CSV: SplFileObject
            $file = new SplFileObject($path, 'r');
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
            $file->setCsvControl($csvDelimiter);

            $headers = null;
            foreach ($file as $row) {
                if ($row === [null] || $row === false) { continue; }
                if ($headers === null) {
                    $headers = array_map(fn($h) => strtolower(trim((string)$h)), $row);
                    continue;
                }
                $assoc = [];
                foreach ($row as $i => $v) {
                    $key = $headers[$i] ?? ('col'.$i);
                    $assoc[$key] = is_scalar($v) ? $v : (string)$v;
                }
                yield $assoc;
            }
            return;
        }

        throw new RuntimeException('Unsupported report extension: ' . $ext);
    }

}