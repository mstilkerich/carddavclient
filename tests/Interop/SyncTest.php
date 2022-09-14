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
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavClient\Services\Sync;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;

/**
 * @psalm-import-type TestAddressbook from TestInfrastructureSrv
 */
final class SyncTest extends TestCase
{
    /**
     * @var array<string,list<string>> $insertedUris Uris inserted to addressbooks by tests in this class
     *    Maps addressbook name to a string[] of the URIs.
     */
    private static $insertedUris;

    /**
     * @var array<string, array{cache: array<string,string>, synctoken: string}>
     *      Simulate a local VCard cache for the sync.
     */
    private static $cacheState;

    public static function setUpBeforeClass(): void
    {
        self::$insertedUris = [];
        self::$cacheState = [];
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
        // try to clean up leftovers
        foreach (self::$insertedUris as $abookname => $uris) {
            $abook = TestInfrastructureSrv::getAddressbook($abookname);
            foreach ($uris as $uri) {
                $abook->deleteCard($uri);
            }
        }
    }

    /** @return array<string, array{string, TestAddressbook}> */
    public function addressbookProvider(): array
    {
        return TestInfrastructureSrv::addressbookProvider();
    }

    /**
     * @param TestAddressbook $cfg
     * @dataProvider addressbookProvider
     */
    public function testInitialSyncWorks(string $abookname, array $cfg): void
    {
        $abook = TestInfrastructureSrv::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        // insert two cards we can expect to be reported by the initial sync
        $createdCards = $this->createCards($abook, $abookname, 2);
        $this->assertCount(2, $createdCards);
        $syncHandler = new SyncTestHandler($abook, true, $createdCards);
        $syncmgr = new Sync();
        $synctoken = $syncmgr->synchronize($abook, $syncHandler);
        $this->assertNotEmpty($synctoken, "Empty synctoken after initial sync");

        // run sync handler's verification routine after the test
        $cacheState = $syncHandler->testVerify();

        self::$cacheState[$abookname] = [
            'cache' => $cacheState,
            'synctoken' => $synctoken
        ];

        if (TestInfrastructureSrv::hasFeature($abookname, TestInfrastructureSrv::BUG_REJ_EMPTY_SYNCTOKEN)) {
            TestInfrastructure::logger()->expectMessage('error', 'sync-collection REPORT produced exception');
        }
    }

    /**
     * @param TestAddressbook $cfg
     * @depends testInitialSyncWorks
     * @dataProvider addressbookProvider
     */
    public function testImmediateFollowupSyncEmpty(string $abookname, array $cfg): void
    {
        $accountname = AccountData::ADDRESSBOOKS[$abookname]["account"];
        $this->assertArrayHasKey($accountname, AccountData::ACCOUNTS);
        $accountcfg = AccountData::ACCOUNTS[$accountname];
        $this->assertArrayHasKey("syncAllowExtraChanges", $accountcfg);

        $abook = TestInfrastructureSrv::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->assertArrayHasKey($abookname, self::$cacheState);

        $syncHandler = new SyncTestHandler(
            $abook,
            $accountcfg["syncAllowExtraChanges"],
            [],
            [],
            self::$cacheState[$abookname]["cache"]
        );
        $syncmgr = new Sync();
        $synctoken = $syncmgr->synchronize($abook, $syncHandler, [], self::$cacheState[$abookname]["synctoken"]);
        $this->assertNotEmpty($synctoken, "Empty synctoken after followup sync");

        // run sync handler's verification routine after the test
        $cacheState = $syncHandler->testVerify();

        self::$cacheState[$abookname] = [
            'cache' => $cacheState,
            'synctoken' => $synctoken
        ];
    }

    /**
     * @param TestAddressbook $cfg
     * @depends testInitialSyncWorks
     * @dataProvider addressbookProvider
     */
    public function testFollowupSyncDifferencesProperlyReported(string $abookname, array $cfg): void
    {
        $accountname = AccountData::ADDRESSBOOKS[$abookname]["account"];
        $this->assertArrayHasKey($accountname, AccountData::ACCOUNTS);
        $accountcfg = AccountData::ACCOUNTS[$accountname];
        $this->assertArrayHasKey("syncAllowExtraChanges", $accountcfg);

        $abook = TestInfrastructureSrv::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->assertArrayHasKey($abookname, self::$cacheState);

        // delete one of the cards inserted earlier
        $delCardUri = array_shift(self::$insertedUris[$abookname]);
        $this->assertNotEmpty($delCardUri);
        $abook->deleteCard($delCardUri);

        // and add one that should be reported as changed
        $createdCards = $this->createCards($abook, $abookname, 1);
        $this->assertCount(1, $createdCards);

        $syncHandler = new SyncTestHandler(
            $abook,
            $accountcfg["syncAllowExtraChanges"],
            $createdCards, // exp changed
            [ $delCardUri ], // exp deleted
            self::$cacheState[$abookname]["cache"]
        );
        $syncmgr = new Sync();
        $synctoken = $syncmgr->synchronize($abook, $syncHandler, [], self::$cacheState[$abookname]["synctoken"]);
        $this->assertNotEmpty($synctoken, "Empty synctoken after followup sync");

        // run sync handler's verification routine after the test
        $cacheState = $syncHandler->testVerify();

        self::$cacheState[$abookname] = [
            'cache' => $cacheState,
            'synctoken' => $synctoken
        ];
    }

    /**
     * @return array<string, array{vcard: VCard, etag: string}>
     */
    private function createCards(AddressbookCollection $abook, string $abookname, int $num): array
    {
        $createdCards = [];
        for ($i = 0; $i < $num; ++$i) {
            $vcard = TestInfrastructure::createVCard();
            [ 'uri' => $cardUri, 'etag' => $cardETag ] = $abook->createCard($vcard);
            $cardUri = TestInfrastructure::normalizeUri($abook, $cardUri);
            $createdCards[$cardUri] = [ "vcard" => $vcard, "etag" => $cardETag ];
            if (!isset(self::$insertedUris[$abookname])) {
                self::$insertedUris[$abookname] = [];
            }
            self::$insertedUris[$abookname][] = $cardUri;
        }

        return $createdCards;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
