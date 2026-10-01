<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2026 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Interop;

use MStilkerich\Tests\CardDavClient\TestInfrastructure;
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavClient\Services\Sync;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\{DataProvider, Depends};
use Sabre\VObject\Component\VCard;

/**
 * Base class for the interoperability tests of the Sync service.
 *
 * The subclasses determine the addressbook object used for the synchronization, which allows to run the same tests
 * against different (simulated) server capabilities.
 *
 * Each variant must be a separate test class, as the tests of one class are executed for all datasets of a test method
 * before proceeding to the next test method. The variants would otherwise interfere by the changes they perform to the
 * addressbook between two syncs.
 *
 * @psalm-import-type TestAddressbook from TestInfrastructureSrv
 */
abstract class SyncTestBase extends TestCase
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

    /**
     * Returns the addressbook object to use for the synchronization in the tests.
     */
    abstract protected function getSyncAddressbook(string $abookname): AddressbookCollection;

    /** @return array<string, array{string, TestAddressbook}> */
    public static function addressbookProvider(): array
    {
        return TestInfrastructureSrv::addressbookProvider();
    }

    /**
     * @param TestAddressbook $cfg
     */
    #[DataProvider('addressbookProvider')]
    public function testInitialSyncWorks(string $abookname, array $cfg): void
    {
        $abook = $this->getSyncAddressbook($abookname);
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

        if (
            $abook->supportsSyncCollection()
            && TestInfrastructureSrv::hasFeature($abookname, TestInfrastructureSrv::BUG_REJ_EMPTY_SYNCTOKEN)
        ) {
            TestInfrastructure::logger()->expectMessage('error', 'sync-collection REPORT produced exception');
        }
    }

    /**
     * @param TestAddressbook $cfg
     */
    #[Depends('testInitialSyncWorks')]
    #[DataProvider('addressbookProvider')]
    public function testImmediateFollowupSyncEmpty(string $abookname, array $cfg): void
    {
        $accountname = AccountData::ADDRESSBOOKS[$abookname]["account"];
        $this->assertArrayHasKey($accountname, AccountData::ACCOUNTS);
        $accountcfg = AccountData::ACCOUNTS[$accountname];
        $this->assertArrayHasKey("syncAllowExtraChanges", $accountcfg);

        $abook = $this->getSyncAddressbook($abookname);
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
     */
    #[Depends('testInitialSyncWorks')]
    #[DataProvider('addressbookProvider')]
    public function testFollowupSyncDifferencesProperlyReported(string $abookname, array $cfg): void
    {
        $accountname = AccountData::ADDRESSBOOKS[$abookname]["account"];
        $this->assertArrayHasKey($accountname, AccountData::ACCOUNTS);
        $accountcfg = AccountData::ACCOUNTS[$accountname];
        $this->assertArrayHasKey("syncAllowExtraChanges", $accountcfg);

        $abook = $this->getSyncAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->assertArrayHasKey($abookname, self::$cacheState);

        // delete one of the cards inserted earlier
        $delCardUri = array_shift(self::$insertedUris[$abookname]);
        $this->assertIsString($delCardUri);
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
