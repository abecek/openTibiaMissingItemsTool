<?php
declare(strict_types=1);

namespace MapMissingItems\Infrastructure\Output;

use Generator;

interface ResultWriterInterface
{
    /**
     * @param Generator $rows
     * @param string $path
     * @return void
     */
    public function write(Generator $rows, string $path): void;
}
