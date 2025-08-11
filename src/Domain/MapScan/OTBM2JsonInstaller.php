<?php
declare(strict_types=1);

namespace MapMissingItems\Domain\MapScan;

use Symfony\Component\Process\Process;

final class OTBM2JsonInstaller
{
    /**
     * Ensures OTBM2JSON repo is cloned and run.js exists.
     * The run.js writes NDJSON (one item per line) to a file.
     *
     * @param string $toolsDir
     * @param callable $progress
     * @return void
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
const fs = require('fs');
const path = require('path');
const otbm2json = require(path.join(__dirname, 'otbm2json.js'));

/**
 * Usage: node run.js <input.otbm> <out.ndjson>
 * Produces NDJSON lines: {"id":123,"x":100,"y":200,"z":7}
 */
async function main() {
  const inPath = process.argv[2];
  const outPath = process.argv[3];
  if (!inPath || !outPath) {
    console.error('Usage: node run.js <input.otbm> <out.ndjson>');
    process.exit(1);
  }

  const out = fs.createWriteStream(outPath, { flags: 'w' });
  const writeLine = (obj) => {
    const ok = out.write(JSON.stringify(obj) + '\n');
    if (!ok) return new Promise(resolve => out.once('drain', resolve));
    return Promise.resolve();
  };

  try {
    const root = otbm2json.read(inPath); // JS object in memory (library is not streaming)
    if (!root || !root.data || !Array.isArray(root.data.nodes)) {
      out.end();
      throw new Error('Unexpected JSON structure from OTBM2JSON');
    }
    for (const node of root.data.nodes) {
      if (!node.features || !Array.isArray(node.features)) continue;
      for (const feature of node.features) {
        const fx = feature.x, fy = feature.y, fz = feature.z;
        if (typeof fx !== 'number' || typeof fy !== 'number' || typeof fz !== 'number') continue;
        const tiles = feature.tiles;
        if (!Array.isArray(tiles)) continue;
        for (const tile of tiles) {
          const tx = tile.x, ty = tile.y;
          if (typeof tx !== 'number' || typeof ty !== 'number') continue;
          const absX = fx + tx;
          const absY = fy + ty;
          const items = tile.items;
          if (!Array.isArray(items)) continue;
          for (const item of items) {
            await collectItem(item, absX, absY, fz, writeLine);
          }
        }
      }
    }
    out.end();
  } catch (e) {
    console.error(e && e.stack ? e.stack : String(e));
    out.end();
    process.exit(2);
  }
}

async function collectItem(item, x, y, z, writeLine) {
  if (item && typeof item.id === 'number') {
    await writeLine({ id: item.id, x, y, z });
  }
  if (item && Array.isArray(item.content)) {
    for (const inside of item.content) {
      await collectItem(inside, x, y, z, writeLine);
    }
  }
}

main();
JS;
            @file_put_contents($runJs, $content);
        } else {
            $progress('run.js already exists');
        }
    }

    /**
     * @return void
     */
    private function assertNode(): void
    {
        $proc = Process::fromShellCommandline('node -v');
        $proc->run();
        if (!$proc->isSuccessful()) {
            throw new \RuntimeException('Node.js is not available in PATH. Please install Node.js (>=14).');
        }
    }
}
