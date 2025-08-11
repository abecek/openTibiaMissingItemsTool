<?php
declare(strict_types=1);

namespace MapMissingItems\Domain\MapScan;

use Symfony\Component\Process\Process;
use RuntimeException;

final class OTBM2JsonRunner
{
    /**
     * Runs node wrapper to read .otbm and returns JSON string.
     *
     * @param string $mapPath
     * @param string $toolsDir
     * @param callable $progress
     * @return string
     */
    public function convert(string $mapPath, string $toolsDir, callable $progress): string
    {
        if (!is_file($mapPath)) {
            throw new RuntimeException('Map file not found: ' . $mapPath);
        }
        $runScript = rtrim($toolsDir, '/') . '/run.js';
        if (!is_file($runScript)) {
            throw new RuntimeException('Missing run.js wrapper. Run installer first.');
        }
        $progress('Launching Node wrapper...');
        $cmd = ['node', $runScript, $mapPath];
        $proc = new Process($cmd, null, null, null, 0);
        $proc->mustRun();
        return $proc->getOutput();
    }
}
