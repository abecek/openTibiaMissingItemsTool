<?php
declare(strict_types=1);

namespace MapMissingItems\Infrastructure\Logging;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

final class LoggerFactory
{
    /**
     * @param string $path
     * @param string $channel
     * @return Logger
     */
    public static function fileLogger(string $path, string $channel = 'app'): Logger
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $logger = new Logger($channel);
        $handler = new StreamHandler($path, Logger::INFO, true, 0644);
        $handler->setFormatter(new LineFormatter(null, null, true, true));
        $logger->pushHandler($handler);
        return $logger;
    }
}
