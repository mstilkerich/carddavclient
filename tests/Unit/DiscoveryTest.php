<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Unit;

use PHPUnit\Framework\TestCase;
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
     *
     * @dataProvider srvRecordsProvider
     */
    public function testSrvRecordsAreSortedByPriorityAndWeight(array $records, array $expTargets): void
    {
        $sortSrvRecords = new \ReflectionMethod(Discovery::class, 'sortSrvRecords');
        if (PHP_VERSION_ID < 80100) {
            $sortSrvRecords->setAccessible(true);
        }
        /** @psalm-var list<SrvRecord> $sorted */
        $sorted = $sortSrvRecords->invoke(null, $records);

        $this->assertSame($expTargets, array_column($sorted, 'target'));
        $this->assertEqualsCanonicalizing($records, $sorted, 'Sorting must not add, drop or alter records');
    }

    /**
     * Test data for the discovery URI parsing test.
     *
     * Each entry consists of the discovery URI and the expected protocol, host, port and path.
     *
     * @return array<string, array{string, array{string, string, string, string}}>
     */
    public static function discoveryUriProvider(): array
    {
        return [
            'Domain only' => [ 'example.com', [ 'https', 'example.com', '443', '/' ] ],
            'Domain with port and path' => [ 'example.com:8443/dav', [ 'https', 'example.com', '8443', '/dav' ] ],
            'Explicit http' => [ 'http://example.com', [ 'http', 'example.com', '80', '/' ] ],
            'Uppercase scheme and host' => [ 'HTTPS://Example.COM/Dav', [ 'https', 'example.com', '443', '/Dav' ] ],
            'IPv4' => [ '192.168.1.10:5232', [ 'https', '192.168.1.10', '5232', '/' ] ],
            'IPv6' => [ 'http://[2001:DB8::1]:8080/dav', [ 'http', '[2001:db8::1]', '8080', '/dav' ] ],
            'IPv6 without scheme' => [ '[2001:db8::1]', [ 'https', '[2001:db8::1]', '443', '/' ] ],
        ];
    }

    /**
     * Tests that discovery URIs are parsed into protocol, host, port and path, with https as default protocol.
     *
     * @param array{string, string, string, string} $expected
     *
     * @dataProvider discoveryUriProvider
     */
    public function testDiscoveryUriIsParsed(string $uri, array $expected): void
    {
        $this->assertSame($expected, self::invokePrivate('parseDiscoveryUri', $uri));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDiscoveryUriProvider(): array
    {
        return [
            'Empty' => [ '' ],
            'No host' => [ 'https:///dav' ],
            'Other scheme' => [ 'ftp://example.com' ],
            'User information' => [ 'https://user@example.com/' ],
        ];
    }

    /**
     * Tests that invalid discovery URIs are rejected.
     *
     * @dataProvider invalidDiscoveryUriProvider
     */
    public function testInvalidDiscoveryUriIsRejected(string $uri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::invokePrivate('parseDiscoveryUri', $uri);
    }

    /**
     * Test data for the isWithinDomain test.
     *
     * Each entry consists of the URI to check, the discovery domain, the protocol of the discovery URI and the
     * expected result.
     *
     * @return array<string, array{string, string, string, bool}>
     */
    public static function withinDomainProvider(): array
    {
        return [
            'Same domain' => [ 'https://example.com/', 'example.com', 'https', true ],
            'Subdomain, other port' => [ 'https://dav.example.com:8443/', 'example.com', 'https', true ],
            'Other domain' => [ 'https://evilexample.com/', 'example.com', 'https', false ],
            'Plain http not requested' => [ 'http://example.com/', 'example.com', 'https', false ],
            'Plain http requested' => [ 'http://example.com/', 'example.com', 'http', true ],
            'Upgrade to https' => [ 'https://example.com/', 'example.com', 'http', true ],
            'IPv4' => [ 'https://192.168.1.10/', '192.168.1.10', 'https', true ],
            'IPv4, other port' => [ 'https://192.168.1.10:8443/', '192.168.1.10', 'https', true ],
            'IPv4, upgrade to https on other port' => [ 'https://192.168.1.10:8443/', '192.168.1.10', 'http', true ],
            'IPv4, other address' => [ 'https://192.168.1.11/', '192.168.1.10', 'https', false ],
            'IPv4, name ending in address' => [ 'https://x.192.168.1.10/', '192.168.1.10', 'https', false ],
            'IPv4-mapped IPv6' => [ 'https://[::ffff:192.168.1.10]:8443/', '192.168.1.10', 'https', true ],
            'IPv6, other notation and port' => [ 'https://[2001:db8:0::1]:8443/', '[2001:db8::1]', 'https', true ],
            'IPv6, other address' => [ 'https://[2001:db8::2]/', '[2001:db8::1]', 'https', false ],
        ];
    }

    /**
     * Tests which URIs are considered to be within the discovery domain.
     *
     * @dataProvider withinDomainProvider
     */
    public function testIsWithinDomain(string $uri, string $domain, string $protocol, bool $expected): void
    {
        $this->assertSame($expected, self::invokePrivate('isWithinDomain', $uri, $domain, $protocol));
    }

    /**
     * Invokes a private static method of the Discovery class.
     *
     * @param mixed ...$args
     * @return mixed
     */
    private static function invokePrivate(string $method, ...$args)
    {
        $m = new \ReflectionMethod(Discovery::class, $method);
        if (PHP_VERSION_ID < 80100) {
            $m->setAccessible(true);
        }
        return $m->invoke(null, ...$args);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
