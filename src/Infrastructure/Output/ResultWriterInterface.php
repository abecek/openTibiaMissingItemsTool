<?php
declare(strict_types=1);

namespace MapMissingItems\Infrastructure\Output;

interface ResultWriterInterface
{
    /** @param \Generator $rows */
    public function write(\Generator $rows, string $path): void;
}
