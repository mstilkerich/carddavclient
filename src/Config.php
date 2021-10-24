<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Psr\Log\{LoggerInterface, NullLogger};

/**
 * Central configuration of the carddavclient library.
 *
 * @package Public\Infrastructure
 *
 * @psalm-type Loglevel = 'emergency'|'alert'|'critical'|'error'|'warning'|'notice'|'info'|'debug'
 */
class Config
{
    /** @var LoggerInterface */
    public static $logger;

    /** @var LoggerInterface */
    public static $httplogger;

    /** @var Loglevel $httpLoglevel The loglevel to use for the HTTP logger. Affects the amount of data logged. */
    public static $httpLoglevel = 'info';

    /**
     * @psalm-param Loglevel $httpLoglevel The loglevel to use for the HTTP logger. Affects the amount of data logged.
     */
    public static function init(
        LoggerInterface $logger = null,
        LoggerInterface $httplogger = null,
        string $httpLoglevel = 'info'
    ): void {
        self::$logger = $logger ?? new NullLogger();
        self::$httplogger = $httplogger ?? new NullLogger();
        self::$httpLoglevel = $httpLoglevel;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
