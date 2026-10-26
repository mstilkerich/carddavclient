<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Unit;

use MStilkerich\CardDavClient\Services\Discovery;

/**
 * Discovery service with simulated DNS lookups.
 */
final class TestDiscovery extends Discovery
{
    /** @var array<string, list<array>> Maps DNS names to the SRV/TXT records returned for that name */
    private $records;

    /**
     * @param array<string, list<array>> $records Maps DNS names to the SRV/TXT records returned for that name
     */
    public function __construct(array $records = [])
    {
        $this->records = $records;
    }

    /**
     * @return array|false
     */
    protected function dnsGetRecord(string $hostname, int $type)
    {
        return $this->records[$hostname] ?? [];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
