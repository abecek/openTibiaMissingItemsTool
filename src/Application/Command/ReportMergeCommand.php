<?php
declare(strict_types=1);

namespace MapMissingItems\Application\Command;

use League\Csv\CannotInsertRecord;
use League\Csv\Exception;
use League\Csv\UnavailableStream;
use MapMissingItems\Domain\Report\ReportReader;
use MapMissingItems\Domain\Report\ReportMerger;
use MapMissingItems\Infrastructure\Output\CsvWriter;
use MapMissingItems\Infrastructure\Output\XlsxEnhancer;
use MapMissingItems\Infrastructure\Output\XlsxWriter;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Writer\Exception\WriterNotOpenedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'report:merge',
    description: 'Merge two reports (CSV/XLSX) produced by map:gaps:scan.'
)]
final class ReportMergeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'base',
                null,
                InputOption::VALUE_REQUIRED,
                'Base report path (.xlsx or .csv)'
            )
            ->addOption(
                'other',
                null,
                InputOption::VALUE_REQUIRED,
                'Other report path (.xlsx or .csv)'
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_OPTIONAL,
                'Output format: xlsx|csv (default inferred from --output or xlsx)', ''
            )
            ->addOption(
                'output',
                null,
                InputOption::VALUE_OPTIONAL,
                'Output path',
                ''
            )
            ->addOption(
                'image-dir',
                null,
                InputOption::VALUE_OPTIONAL,
                'Directory with <id>.png icons for XLSX (optional)',
                null
            )
            ->addOption(
                'csv-delimiter-base',
                null,
                InputOption::VALUE_OPTIONAL, 'CSV delimiter for BASE', ',')
            ->addOption(
                'csv-delimiter-other',
                null,
                InputOption::VALUE_OPTIONAL,
                'CSV delimiter for OTHER',
                ','
            )
            ->addOption(
                'sort',
                null,
                InputOption::VALUE_OPTIONAL,
                'Sort order: occurrences | id-asc',
                'occurrences'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws CannotInsertRecord
     * @throws Exception
     * @throws UnavailableStream
     * @throws IOException
     * @throws WriterNotOpenedException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base = (string)$input->getOption('base');
        $other = (string)$input->getOption('other');
        if ($base === '' || !is_file($base)) { $output->writeln('<error>Invalid --base path</error>'); return Command::FAILURE; }
        if ($other === '' || !is_file($other)) { $output->writeln('<error>Invalid --other path</error>'); return Command::FAILURE; }

        $format = strtolower((string)$input->getOption('format'));
        $out = (string)$input->getOption('output');

        if ($out === '') {
            // default output based on format (or infer from base extension)
            if ($format === 'csv') {
                $out = 'data/output/missing-items-merged.csv';
            } else {
                $out = 'data/output/missing-items-merged.xlsx';
                $format = 'xlsx'; // default
            }
        } else {
            // infer format from output extension if not explicitly set
            if ($format === '') {
                $ext = strtolower(pathinfo($out, PATHINFO_EXTENSION));
                $format = in_array($ext, ['csv','xlsx'], true) ? $ext : 'xlsx';
                if (!in_array($ext, ['csv','xlsx'], true)) {
                    $out .= '.xlsx';
                }
            }
        }

        // Safety: don't overwrite input files
        if ((@realpath($out) !== false && @realpath($out) === @realpath($base)) ||
            (@realpath($out) !== false && @realpath($out) === @realpath($other))) {
            $output->writeln('<error>Output must be different from input files.</error>');
            return Command::FAILURE;
        }

        $csvBase = (string)$input->getOption('csv-delimiter-base');
        $csvOther = (string)$input->getOption('csv-delimiter-other');
        $sort = strtolower((string)$input->getOption('sort'));
        if (!in_array($sort, ['occurrences','id-asc'], true)) {
            $output->writeln('<error>Invalid --sort (allowed: occurrences, id-asc)</error>');
            return Command::FAILURE;
        }
        $imageDir = $input->getOption('image-dir');
        $imageDir = $imageDir !== null ? (string)$imageDir : null;

        $output->writeln('<info>Merging reports</info>');

        // [1/5] Reading BASE
        $output->writeln('[1/5] Reading BASE report: ' . $base);
        $bar = new ProgressBar($output, 1); $bar->start();
        $reader = new ReportReader();
        $baseRows = iterator_to_array($reader->read($base, 0, $csvBase), false);
        $bar->advance(); $bar->finish(); $output->writeln('');
        $output->writeln('  - BASE rows: ' . count($baseRows));

        // [2/5] Reading OTHER
        $output->writeln('[2/5] Reading OTHER report: ' . $other);
        $bar = new ProgressBar($output, 1); $bar->start();
        $otherRows = iterator_to_array($reader->read($other, 0, $csvOther), false);
        $bar->advance(); $bar->finish(); $output->writeln('');
        $output->writeln('  - OTHER rows: ' . count($otherRows));

        // [3/5] Merging
        $output->writeln('[3/5] Merging & sorting (' . $sort . ')');
        $bar = new ProgressBar($output, 1); $bar->start();
        $merger = new ReportMerger();
        $mergedRows = iterator_to_array($merger->merge($baseRows, $otherRows, $sort), false);
        $bar->advance(); $bar->finish(); $output->writeln('');
        $output->writeln('  - Result rows: ' . count($mergedRows));

        // [4/5] Writing output (from scratch)
        $output->writeln('[4/5] Writing output: ' . $out);
        if (!is_dir(dirname($out))) { @mkdir(dirname($out), 0775, true); }

        if ($format === 'csv') {
            (new CsvWriter())->write((function() use ($mergedRows) {
                foreach ($mergedRows as $r) { yield $r; }
            })(), $out);
        } else {
            (new XlsxWriter())->write((function() use ($mergedRows) {
                foreach ($mergedRows as $r) { yield $r; }
            })(), $out, $imageDir);
        }
        $output->writeln('<info>Saved:</info> ' . $out);

        // [5/5] Enhance XLSX (only for XLSX)
        if ($format === 'xlsx') {
            $output->writeln('<info>Enhancing XLSX…</info>');
            // dynamic progress: init with 1, enhancer ustawi maxSteps
            $enhanceBar = new ProgressBar($output, 1);
            $enhanceBar->start();

            try {
                XlsxEnhancer::enhance(
                    $out,
                    100000, // safety cap
                    function (string $msg, int $advanceBy = 1, ?int $setMax = null) use ($output, $enhanceBar) {
                        if ($setMax !== null) {
                            $enhanceBar->setMaxSteps($setMax);
                            $enhanceBar->display();
                            return;
                        }
                        if ($advanceBy > 0) {
                            $enhanceBar->advance($advanceBy);
                        }
                        $output->writeln('  - ' . $msg);
                    },
                    100 // rowChunk for progress granularity
                );
                $enhanceBar->finish();
                $output->writeln('');
                $output->writeln('<info>Enhancement done.</info>');
            } catch (Throwable $e) {
                $enhanceBar->clear();
                $output->writeln('<comment>Enhancement skipped: ' . $e->getMessage() . '</comment>');
            }
        }

        $peak = memory_get_peak_usage(true)/1048576;
        $output->writeln(sprintf('Peak memory: %.2f MB', $peak));

        return Command::SUCCESS;
    }
}
