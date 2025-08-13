<?php
declare(strict_types=1);

namespace MapMissingItems\Application\Command;

use MapMissingItems\Domain\ItemsXml\ItemsXmlAugmenter;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
                'output',
                null,
                InputOption::VALUE_OPTIONAL,
                'Destination items.xml (work on a copy instead of modifying the source)',
                null
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
        $outputPath   = $input->getOption('output');
        $outputPath   = $outputPath !== null ? (string)$outputPath : null;

        $reportPath   = (string)$input->getOption('report');
        if ($reportPath === '') {
            $xlsxDefault = 'data/output/missing-items.xlsx';
            $csvDefault  = 'data/output/missing-items.csv';
            if (is_file($xlsxDefault))      { $reportPath = $xlsxDefault; }
            elseif (is_file($csvDefault))   { $reportPath = $csvDefault; }
            else {
                throw new RuntimeException('Report file not specified and no default report found in data/output/.');
            }
        }

        $sheetIndex  = max(0, (int)$input->getOption('sheet'));
        $rowChunk = max(1, (int)$input->getOption('row-chunk'));
        $csvDelimiter = (string)$input->getOption('csv-delimiter');
        $dryRun = (bool)$input->getOption('dry-run');
        $backup = !(bool)$input->getOption('no-backup');

        $output->writeln('<info>Augmenting items.xml</info>');
        $bar = new ProgressBar($output, 5); // coarse steps
        $bar->start();

        $augmenter = new ItemsXmlAugmenter();
        $summary = $augmenter->augment([
            'itemsXmlPath' => $itemsXmlPath,
            'outputXmlPath' => $outputPath,
            'reportPath' => $reportPath,
            'sheetIndex' => $sheetIndex,
            'csvDelimiter' => $csvDelimiter,
            'rowChunk' => $rowChunk,
            'dryRun' => $dryRun,
            'backup' => $backup,
        ], function (string $msg) use ($output, $bar) {
            $bar->advance(); // coarse tick per message
            $output->writeln('  - ' . $msg);
        });

        // finalize bar to 100%
        $bar->finish();
        $output->writeln('');

        $output->writeln(sprintf(
            '<info>Done.</info> %s rows scanned, %s candidate IDs, %s groups. %s%s',
            $summary['consideredRows'],
            $summary['candidateIds'],
            $summary['groups'],
            $summary['dryRun'] ? 'DRY-RUN, no changes written.' : 'Changes appended.',
            $summary['dryRun'] ? '' : (' Working file: ' . $summary['workingXml'])
        ));

        return Command::SUCCESS;
    }
}