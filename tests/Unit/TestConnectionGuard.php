<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Unit;

use MStilkerich\CardDavClient\ConnectionGuard;

/**
 * A ConnectionGuard that resolves hostnames from a fixed table instead of using the resolver of the system.
 */
final class TestConnectionGuard extends ConnectionGuard
{
    /** @var array<array-key, list<string>> Maps hostnames to their addresses */
    private const ADDRESSES = [
        'public.example.com' => [ '93.184.216.34' ],
        'dual.example.com' => [ '93.184.216.34', '2606:2800:220:1::1', '93.184.216.34' ],
        'notation.example.com' => [ '::ffff:127.0.0.1', '0:0:0:0:0:0:0:1', 'FD00:0::5', '127.0.0.1', 'invalid' ],
        'loopback.example.com' => [ '127.0.0.1' ],
        '2130706433' => [ '127.0.0.1' ],
    ];

    /** @var int Number of name resolutions performed */
    public $numLookups = 0;

    protected function resolveHost(string $host): array
    {
        ++$this->numLookups;
        return self::ADDRESSES[$host] ?? [];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
