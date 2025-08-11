<?php
declare(strict_types=1);

namespace MapMissingItems\Domain\MapScan;

use Symfony\Component\Process\Process;
use RuntimeException;
use Random\RandomException;

final class OTBM2JsonRunner
{
    /**
     * Runs the Node wrapper to produce an NDJSON file with items.
     * Returns the path to the generated NDJSON file.
     *
     * @param string $mapPath   Absolute or relative path to .otbm
     * @param string $toolsDir  Directory where otbm2json repo resides
     * @param string $tmpDir    Directory where the NDJSON file will be created
     * @param int    $nodeMaxOldSpaceMB V8 memory in MB for --max-old-space-size
     * @param callable|null $progress Optional progress logger callback
     * @return string
     * @throws RandomException
     */
    public function convertToNdjson(
        string $mapPath,
        string $toolsDir,
        string $tmpDir,
        int $nodeMaxOldSpaceMB = 2048,
        callable $progress = null
    ): string {
        if (!is_file($mapPath)) {
            throw new RuntimeException('Map file not found: ' . $mapPath);
        }
        $runScript = rtrim($toolsDir, '/') . '/run.js';
        if (!is_file($runScript)) {
            throw new RuntimeException('Missing run.js wrapper. Run installer first.');
        }

        $tmpDir = rtrim($tmpDir, DIRECTORY_SEPARATOR);
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $outPath = $tmpDir . DIRECTORY_SEPARATOR . 'items_' . bin2hex(random_bytes(6)) . '.ndjson';

        $env = [
            // Increase Node V8 memory limit if needed
            'NODE_OPTIONS' => '--max-old-space-size=' . $nodeMaxOldSpaceMB
        ];
        $cmd = ['node', $runScript, $mapPath, $outPath];
        if ($progress) {
            $progress('Launching Node wrapper...');
        }

        $stderr = '';
        $proc = new Process($cmd, null, $env, null, 0); // no timeout
        // Do not accumulate large stdout into PHP memory; capture only stderr for diagnostics
        $proc->run(function ($type, $buffer) use (&$stderr) {
            if ($type === Process::ERR) {
                $stderr .= $buffer;
            }
        });

        if (!$proc->isSuccessful()) {
            throw new RuntimeException(
                'Node wrapper failed with code ' . $proc->getExitCode() . "\n" . $stderr
            );
        }

        return $outPath;
    }
}
