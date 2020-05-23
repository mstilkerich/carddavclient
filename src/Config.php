<?php

/**
 * Class Config
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Psr\Log\{LoggerInterface, NullLogger};

class Config
{
    /** @var LoggerInterface */
    public static $logger;

    /** @var LoggerInterface */
    public static $httplogger;

    public static function init(LoggerInterface $logger = null, LoggerInterface $httplogger = null): void
    {
        self::$logger = $logger ?? new NullLogger();
        self::$httplogger = $httplogger ?? new NullLogger();
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
