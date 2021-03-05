<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (C) 2020 Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of PHP-CardDavClient.
 *
 * PHP-CardDavClient is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PHP-CardDavClient is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PHP-CardDavClient.  If not, see <https://www.gnu.org/licenses/>.
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
