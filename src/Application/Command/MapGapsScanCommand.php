<?php
declare(strict_types=1);

namespace MapMissingItems\Application\Command;

use MapMissingItems\Domain\ItemsXml\XmlItemsLoader;
use MapMissingItems\Domain\MapScan\ItemOccurrenceCounter;
use MapMissingItems\Domain\MapScan\MapJsonLoader;
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
use JsonException;

#[AsCommand(
    name: 'map:gaps:scan',
    description: 'Find items used on the OTBM map that are not present in items.xml.'
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
             );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $itemsXml = (string)$input->getOption('items-xml');
        if ($itemsXml === '' || !is_file($itemsXml)) {
            $output->writeln('<error>Missing or invalid path for --items-xml</error>');
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
                $output->writeln('<error>--sample musi być liczbą całkowitą >= 1</error>');
                return Command::FAILURE;
            }
            $sampleN = max(1, (int)$sample);
        }

        $logger = LoggerFactory::fileLogger($logFile);
        $logger->info('--- map:gaps:scan START ---', [
            'itemsXml' => $itemsXml,
            'map' => $mapPath,
            'format' => $format,
            'output' => $outputPath,
            'toolsDir' => $toolsDir,
            'sample' => $sampleN,
            'php' => PHP_VERSION
        ]);

        // Progress #1: installer
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

        // Progress #2: OTBM -> JSON
        $output->writeln('<info>[2/6] OTBM → JSON conversion</info>');
        $bar = new ProgressBar($output, 1);
        $bar->start();
        $runner = new OTBM2JsonRunner();
        $json = $runner->convert($mapPath, $toolsDir, function(string $msg) use ($output, $logger) {
            $output->writeln('  - ' . $msg);
            $logger->info('[runner] ' . $msg);
        });
        $bar->advance();
        $bar->finish();
        $output->writeln('');
        $logger->info('Conversion finished, JSON bytes: ' . strlen($json) . ' bajtów JSON');

        // Progress #3: parse JSON
        $output->writeln('<info>[3/6] Loading map JSON</info>');
        $bar = new ProgressBar($output, 1);
        $bar->start();
        $loader = new MapJsonLoader();
        $items = $loader->loadItems($json);
        $bar->advance();
        $bar->finish();
        $output->writeln(sprintf("\nNumber of item records found: %d", count($items)));
        $logger->info('Item records (full): ' . count($items));

        if ($sampleN !== null && count($items) > $sampleN) {
            $items = array_slice($items, 0, $sampleN);
            $output->writeln(sprintf('<comment>Sampling mode: limited to %d rekordów</comment>', $sampleN));
            $logger->info('Sampling mode active', ['limit' => $sampleN]);
        }

        // Progress #4: items.xml -> index
        $output->writeln('<info>[4/6] Building items.xml index</info>');
        $bar = new ProgressBar($output, 1);
        $bar->start();
        $xmlLoader = new XmlItemsLoader();
        $index = $xmlLoader->load($itemsXml);
        $bar->advance();
        $bar->finish();
        $output->writeln('');
        $logger->info('items.xml index built');

        // Progress #5: analyze
        $output->writeln('<info>[5/6] Analyzing and counting missing IDs</info>');
        $steps = max(1, intdiv(max(1, count($items)), 10000));
        $bar = new ProgressBar($output, $steps);
        $bar->start();
        $counter = new ItemOccurrenceCounter();
        $counter->count($items, $index, function(int $n) use ($bar, $logger) {
            $bar->advance();
            $logger->info('Processed items', ['count' => $n]);
        });
        $bar->finish();
        $output->writeln('');

        // Progress #6: export
        $output->writeln('<info>[6/6] Exporting results</info>');
        $writer = $format === 'csv' ? new CsvWriter() : new XlsxWriter();
        $writer->write($counter->result(), $outputPath);
        $output->writeln(sprintf('<info>Saved: %s</info>', $outputPath));
        $logger->info('Results saved', ['output' => $outputPath, 'format' => $format]);

        // Stats
        $peak = memory_get_peak_usage(true)/1048576;
        $output->writeln('---');
        $output->writeln(sprintf('Peak memory usage: %.2f MB', $peak));
        $logger->info('--- map:gaps:scan END ---', ['peakMB' => $peak]);

        return Command::SUCCESS;
    }
}
