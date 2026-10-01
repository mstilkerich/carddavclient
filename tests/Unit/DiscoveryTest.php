<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2026 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use MStilkerich\CardDavClient\Services\Discovery;
use MStilkerich\Tests\CardDavClient\TestInfrastructure;

/**
 * @psalm-import-type SrvRecord from Discovery
 */
final class DiscoveryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestInfrastructure::init();
    }

    /**
     * Creates an SRV record as returned by dns_get_record().
     *
     * @psalm-return SrvRecord
     */
    private static function srv(string $target, int $pri, int $weight): array
    {
        return [ 'pri' => $pri, 'weight' => $weight, 'target' => $target, 'port' => 443 ];
    }

    /**
     * Test data for the SRV record sorting test.
     *
     * Each entry consists of the unsorted SRV records and the list of targets in the expected order.
     *
     * @return array<string, array{list<SrvRecord>, list<string>}>
     */
    public static function srvRecordsProvider(): array
    {
        return [
            'Empty' => [ [], [] ],
            'Single record' => [ [ self::srv('a', 10, 5) ], [ 'a' ] ],
            'Lower priority value preferred' => [
                [ self::srv('c', 30, 0), self::srv('a', 10, 0), self::srv('b', 20, 0) ],
                [ 'a', 'b', 'c' ],
            ],
            'Higher weight preferred within same priority' => [
                [ self::srv('c', 10, 0), self::srv('a', 10, 60), self::srv('b', 10, 40) ],
                [ 'a', 'b', 'c' ],
            ],
            'Priority takes precedence over weight' => [
                [
                    self::srv('d', 20, 50),
                    self::srv('b', 10, 1),
                    self::srv('c', 20, 100),
                    self::srv('a', 10, 5),
                ],
                [ 'a', 'b', 'c', 'd' ],
            ],
            'Already sorted' => [
                [ self::srv('a', 0, 10), self::srv('b', 0, 0), self::srv('c', 1, 10) ],
                [ 'a', 'b', 'c' ],
            ],
        ];
    }

    /**
     * Tests that SRV records are ordered by ascending priority and descending weight.
     *
     * @param list<SrvRecord> $records
     * @param list<string> $expTargets
     */
    #[DataProvider('srvRecordsProvider')]
    public function testSrvRecordsAreSortedByPriorityAndWeight(array $records, array $expTargets): void
    {
        $sortSrvRecords = new \ReflectionMethod(Discovery::class, 'sortSrvRecords');
        /** @psalm-var list<SrvRecord> $sorted */
        $sorted = $sortSrvRecords->invoke(null, $records);

        $this->assertSame($expTargets, array_column($sorted, 'target'));
        $this->assertEqualsCanonicalizing($records, $sorted, 'Sorting must not add, drop or alter records');
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
