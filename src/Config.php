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
 */
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
