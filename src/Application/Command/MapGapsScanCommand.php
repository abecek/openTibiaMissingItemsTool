<?php
declare(strict_types=1);

namespace MapMissingItems\Application\Command;

use MapMissingItems\Domain\ItemsXml\XmlItemsLoader;
use MapMissingItems\Domain\MapScan\ItemOccurrenceCounter;
use MapMissingItems\Domain\MapScan\MapNdjsonReader;
use MapMissingItems\Domain\MapScan\OTBM2JsonInstaller;
use MapMissingItems\Domain\MapScan\OTBM2JsonRunner;
use MapMissingItems\Infrastructure\Logging\LoggerFactory;
use MapMissingItems\Infrastructure\Output\CsvWriter;
use MapMissingItems\Infrastructure\Output\XlsxWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use League\Csv\CannotInsertRecord;
use League\Csv\Exception;
use League\Csv\UnavailableStream;
use MapMissingItems\Infrastructure\Output\XlsxEnhancer;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Writer\Exception\WriterNotOpenedException;
use Random\RandomException;
use Throwable;

#[AsCommand(
    name: 'map:gaps:scan',
    description: 'Find items placed on OTBM map that do not exist in items.xml.'
)]
final class MapGapsScanCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'items-xml',
            null,
            InputOption::VALUE_OPTIONAL,
            'Path to items.xml',
            'data/input/items.xml'
             )
             ->addOption(
                 'map',
                 null,
                 InputOption::VALUE_OPTIONAL,
                 'Path to world.otbm',
                 'data/input/world.otbm'
             )
             ->addOption(
                 'format',
                 null,
                 InputOption::VALUE_OPTIONAL,
                 'csv|xlsx',
                 'xlsx'
             )
             ->addOption(
                 'output',
                 null,
                 InputOption::VALUE_OPTIONAL,
                 'Output file',
                 'data/output/missing-items.xlsx'
             )
             ->addOption(
                 'tools-dir',
                 null,
                 InputOption::VALUE_OPTIONAL,
                 'Tools directory (OTBM2JSON)',
                 'tools/otbm2json'
             )
             ->addOption(
                 'log-file',
                 null,
                 InputOption::VALUE_OPTIONAL,
                 'Monolog log file',
                 'logs/app.log'
             )
             ->addOption(
                 'sample',
                 null,
                 InputOption::VALUE_OPTIONAL,
                 'Sampling: limit the number of map records to N (quick dry run)'
             )
            ->addOption(
                'node-max-old-space',
                null,
                InputOption::VALUE_OPTIONAL,
                'V8 memory (MB) for Node --max-old-space-size',
                '2048'
            )
            ->addOption(
                'tmp-dir',
                null,
                InputOption::VALUE_OPTIONAL,
                'Temp directory for NDJSON output',
                'data/output/tmp'
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
     * @throws RandomException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $itemsXml = (string)$input->getOption('items-xml');
        if ($itemsXml === '' || !is_file($itemsXml)) {
            $output->writeln('<error>Missing or invalid --items-xml</error>');
            return Command::FAILURE;
        }
        $mapPath = (string)$input->getOption('map');
        if (!is_file($mapPath)) {
            $output->writeln(sprintf('<error>Map file not found: %s</error>', $mapPath));
            return Command::FAILURE;
        }
        $format = strtolower((string)$input->getOption('format'));
        if (!in_array($format, ['csv','xlsx'], true)) {
            $output->writeln('<error>Invalid --format (allowed: csv, xlsx)</error>');
            return Command::FAILURE;
        }
        $outputPath = (string)$input->getOption('output');
        if ($format === 'csv' && !str_ends_with($outputPath, '.csv')) {
            $outputPath = 'data/output/missing-items.csv';
        } elseif ($format === 'xlsx' && !str_ends_with($outputPath, '.xlsx')) {
            $outputPath = 'data/output/missing-items.xlsx';
        }
        $toolsDir = (string)$input->getOption('tools-dir');
        $logFile = (string)$input->getOption('log-file');

        $sample = $input->getOption('sample');
        $sampleN = null;
        if ($sample !== null) {
            if (!ctype_digit((string)$sample)) {
                $output->writeln('<error>--sample must be an integer >= 1</error>');
                return Command::FAILURE;
            }
            $sampleN = max(1, (int)$sample);
        }

        $nodeMax = (int)$input->getOption('node-max-old-space') ?: 2048;

        $tmpDir = (string)$input->getOption('tmp-dir');
        if ($tmpDir === '') { $tmpDir = 'data/output/tmp'; }
        if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0775, true); }

        $logger = LoggerFactory::fileLogger($logFile);
        $logger->info('--- map:gaps:scan START ---', [
            'itemsXml' => $itemsXml,
            'map' => $mapPath,
            'format' => $format,
            'output' => $outputPath,
            'toolsDir' => $toolsDir,
            'sample' => $sampleN,
            'nodeMaxOldSpaceMB' => $nodeMax,
            'tmpDir' => $tmpDir,
            'php' => PHP_VERSION
        ]);

        // [1/6] Prepare tools
        $output->writeln('<info>[1/6] Preparing tools</info>');
        $bar = new ProgressBar($output, 3);
        $bar->start();
        $installer = new OTBM2JsonInstaller();
        $installer->ensureInstalled($toolsDir, function(string $msg) use ($output, $bar, $logger) {
            $bar->advance();
            $output->writeln('  - ' . $msg);
            $logger->info('[installer] ' . $msg);
        });
        $bar->finish();
        $output->writeln('');

        // [2/6] OTBM -> NDJSON
        $output->writeln('<info>[2/6] OTBM → NDJSON conversion</info>');
        $bar = new ProgressBar($output, 1);
        $bar->start();
        $runner = new OTBM2JsonRunner();
        $ndjsonPath = $runner->convertToNdjson($mapPath, $toolsDir, $tmpDir, $nodeMax, function(string $msg) use ($output, $logger) {
            $output->writeln('  - ' . $msg);
            $logger->info('[runner] ' . $msg);
        });
        $bar->advance();
        $bar->finish();
        $output->writeln('');

        // Verify the NDJSON file
        $size = is_file($ndjsonPath) ? filesize($ndjsonPath) : 0;
        if ($size === 0) {
            $output->writeln('<error>NDJSON file was not created or is empty: ' . $ndjsonPath . '</error>');
            $logger->error('NDJSON missing/empty', ['path' => $ndjsonPath]);
            return Command::FAILURE;
        }
        $logger->info('NDJSON file created', ['path' => $ndjsonPath, 'sizeBytes' => $size]);

        // [3/6] Open NDJSON stream
        $output->writeln('<info>[3/6] Opening NDJSON stream</info>');
        $bar = new ProgressBar($output, 1);
        $bar->start();
        $reader = new MapNdjsonReader();
        $itemsIterable = $reader->iterateItems($ndjsonPath);
        $bar->advance();
        $bar->finish();
        $output->writeln('');

        // [4/6] Build items.xml index
        $output->writeln('<info>[4/6] Building items.xml index</info>');
        $bar = new ProgressBar($output, 1);
        $bar->start();
        $xmlLoader = new XmlItemsLoader();
        $index = $xmlLoader->load($itemsXml);
        $bar->advance();
        $bar->finish();
        $output->writeln('');
        $logger->info('items.xml index built');

        // [5/6] Analyze & count (streaming)
        $output->writeln('<info>[5/6] Analyzing and counting missing IDs</info>');
        $bar = new ProgressBar($output, 0); // indeterminate
        $bar->start();
        $counter = new ItemOccurrenceCounter();
        $counter->count($itemsIterable, $index, $sampleN, function(int $n) use ($bar, $logger) {
            $bar->advance();
            $logger->info('Processed items', ['count' => $n]);
        });
        $bar->finish();
        $output->writeln('');

        // [6/6] Export
        $output->writeln('<info>[6/6] Exporting results</info>');
        $writer = $format === 'csv' ? new CsvWriter() : new XlsxWriter();
        $writer->write($counter->result(), $outputPath);
        $output->writeln(sprintf('<info>Saved: %s</info>', $outputPath));
        $output->writeln('<info>Enhancing XLSX (filters, auto-size, freeze top row)...</info>');
        try {
            XlsxEnhancer::enhance($outputPath, 100000);
            $output->writeln('<info>Enhancement done.</info>');
        } catch (Throwable $e) {
            $output->writeln('<comment>Enhancement skipped: ' . $e->getMessage() . '</comment>');
        }
        $logger->info('Results saved', ['output' => $outputPath, 'format' => $format]);

        // Cleanup temp NDJSON
        @unlink($ndjsonPath);

        // Stats
        $peak = memory_get_peak_usage(true)/1048576;
        $output->writeln('---');
        $output->writeln(sprintf('Peak memory: %.2f MB', $peak));
        $logger->info('--- map:gaps:scan END ---', ['peakMB' => $peak]);

        return Command::SUCCESS;
    }
}
