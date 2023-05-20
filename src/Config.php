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
 * @psalm-type LibOptionsInput = array{
 *   guzzle_logformat?: string,
 * }
 *
 * @psalm-type LibOptions = array{
 *   guzzle_logformat: string,
 * }
 */
class Config
{
    public const GUZZLE_LOGFMT_DEBUG =
        '"{method} {target} HTTP/{version}" {code}' . "\n>>>>>>>>\n{request}\n<<<<<<<<\n{response}\n--------\n{error}";

    public const GUZZLE_LOGFMT_SHORT =
        '"{method} {target} HTTP/{version}" {code} {res_header_Content-Length}';

    /** @var LoggerInterface */
    public static $logger;

    /** @var LoggerInterface */
    public static $httplogger;

    /**
     * Configuration options of the library.
     * @var LibOptions
     * @psalm-readonly-allow-private-mutation
     */
    public static $options = [
        'guzzle_logformat' => self::GUZZLE_LOGFMT_DEBUG,
    ];

    /**
     * Initialize the library.
     *
     * The functions accepts two logger objects complying with the standard PSR-3 Logger interface, one of which is used
     * by the carddavclient lib itself, the other is passed to the HTTP client library to log the HTTP traffic. Pass
     * null for any logger to disable the corresponding logging.
     *
     * The $options parameter allows to override the default behavior of the library in various options. It is an
     * associative array that may have the following keys and values:
     *   - guzzle_logformat(string):
     *     Value is a string defining the HTTP log format when Guzzle is used as HTTP client library. See the
     *     documentation of the Guzzle MessageFormatter class for the available placeholders. This class offers two
     *     constants that can be used as template:
     *     - {@see Config::GUZZLE_LOGFMT_SHORT}: A compact log format with only request type/URI and the response status
     *       and content length
     *     - {@see Config::GUZZLE_LOGFMT_DEBUG}: The default, which logs the full HTTP traffic including request bodies
     *       and response bodies.
     *
     * @psalm-param LibOptionsInput $options Options to override defaults.
     */
    public static function init(
        LoggerInterface $logger = null,
        LoggerInterface $httplogger = null,
        array $options = []
    ): void {
        self::$logger = $logger ?? new NullLogger();
        self::$httplogger = $httplogger ?? new NullLogger();
        self::$options = $options + self::$options;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
