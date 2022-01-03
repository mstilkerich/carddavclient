<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Interop;

use MStilkerich\Tests\CardDavClient\TestInfrastructure;
use MStilkerich\CardDavClient\Account;
use MStilkerich\CardDavClient\Services\Discovery;
use PHPUnit\Framework\TestCase;

/**
 * @psalm-import-type TestAccount from TestInfrastructureSrv
 */
final class DiscoveryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestInfrastructureSrv::init();
    }

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
    }

    public static function tearDownAfterClass(): void
    {
    }


    /**
     * @return array<string, array{string, TestAccount}>
     */
    public function accountProvider(): array
    {
        return TestInfrastructureSrv::accountProvider();
    }

    /**
     * @param TestAccount $cfg
     * @dataProvider accountProvider
     */
    public function testAllAddressbooksCanBeDiscovered(string $accountname, array $cfg): void
    {
        $account = TestInfrastructureSrv::getAccount($accountname);
        $this->assertInstanceOf(Account::class, $account);

        $abookUris = [];
        foreach (AccountData::ADDRESSBOOKS as $abookname => $abookcfg) {
            if ($abookcfg['account'] === $accountname) {
                $abook = TestInfrastructureSrv::getAddressbook($abookname);
                if ($abook->getAccount() === $account) {
                    $abookUris[] = TestInfrastructure::normalizeUri($abook, $abook->getUri());
                }
            }
        }

        $discover = new Discovery();
        $abooks = $discover->discoverAddressbooks($account);

        $this->assertCount(count($abookUris), $abooks, "Unexpected number of addressbooks discovered");

        foreach ($abooks as $abook) {
            $uri = TestInfrastructure::normalizeUri($abook, $abook->getUri());
            $this->assertContains($uri, $abookUris, "Unexpected addressbook discovered");
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
