<?php
declare(strict_types=1);

namespace EK\MapItemGaps\Domain\MapScan;

use Symfony\Component\Process\Process;

final class OTBM2JsonInstaller
{
    /**
     * Ensures OTBM2JSON repo is cloned and wrapper run.js exists.
     * $progress callback gets short status messages.
     */
    public function ensureInstalled(string $toolsDir, callable $progress): void
    {
        $this->assertNode();
        $progress('Node.js OK');

        $repoDir = rtrim($toolsDir, '/');
        if (!is_dir($repoDir)) {
            $progress('Cloning OTBM2JSON repository...');
            $proc = Process::fromShellCommandline(
                'git clone https://github.com/Inconcessus/OTBM2JSON.git ' . escapeshellarg($repoDir)
            );
            $proc->mustRun();
        } else {
            $progress('OTBM2JSON already present');
        }

        $runJs = $repoDir . '/run.js';
        if (!is_file($runJs)) {
            $progress('Creating run.js wrapper...');
            $content = <<<'JS'
            const path = require('path');
            const otbm2json = require(path.join(__dirname, 'otbm2json.js'));
            const otbmPath = process.argv[2];
            if (!otbmPath) {
              console.error('Usage: node run.js <path-to-otbm>');
              process.exit(1);
            }
            try {
              const data = otbm2json.read(otbmPath);
              process.stdout.write(JSON.stringify(data));
            } catch (e) {
              console.error(e && e.stack ? e.stack : String(e));
              process.exit(2);
            }
            JS;
            @file_put_contents($runJs, $content);
        } else {
            $progress('run.js already exists');
        }
    }

    private function assertNode(): void
    {
        $proc = Process::fromShellCommandline('node -v');
        $proc->run();
        if (!$proc->isSuccessful()) {
            throw new \RuntimeException('Node.js is not available in PATH. Please install Node.js (>=14).');
        }
    }
}
