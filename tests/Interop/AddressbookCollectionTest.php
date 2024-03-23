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
use MStilkerich\CardDavClient\{Account,AddressbookCollection,WebDavResource};
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;
use MStilkerich\Tests\CardDavClient\Interop\TestInfrastructureSrv as TIS;

/**
 * @psalm-import-type TestAddressbook from TestInfrastructureSrv
 */
final class AddressbookCollectionTest extends TestCase
{
    /**
     * @var array<string, array{vcard: VCard, uri: string, etag: string}>
     *    Cards inserted to addressbooks by tests in this class. Maps addressbook name to an associative array.
     */
    private static $insertedCards;

    public static function setUpBeforeClass(): void
    {
        TIS::init();
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

    /** @return array<string, array{string, TestAddressbook}> */
    public function addressbookProvider(): array
    {
        return TIS::addressbookProvider();
    }

    /**
     * @param TestAddressbook $cfg
     * @dataProvider addressbookProvider
     */
    public function testPropertiesCorrectlyReported(string $abookname, array $cfg): void
    {
        $abook = TIS::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        $this->assertSame($cfg["displayname"], $abook->getDisplayName(), "Displayname");
        $this->assertSame($cfg["description"], $abook->getDescription(), "Displayname");

        $pathcomp = explode("/", rtrim($cfg["url"], "/"));
        $expAbName = $cfg["displayname"] ?? end($pathcomp);
        $this->assertSame($expAbName, $abook->getName(), "Name");

        $abookStringified = (string) $abook;
        $this->assertStringContainsString($expAbName, $abookStringified);
        $this->assertStringContainsString($cfg["url"], $abookStringified);

        $this->assertSame(
            TIS::hasFeature($abookname, TIS::FEAT_SYNCCOLL),
            $abook->supportsSyncCollection(),
            "SyncCollection support"
        );
        $this->assertSame(
            TIS::hasFeature($abookname, TIS::FEAT_MULTIGET),
            $abook->supportsMultiGet(),
            "MultiGet report"
        );

        $ctag = $abook->getCTag();
        if (TIS::hasFeature($abookname, TIS::FEAT_CTAG)) {
            $this->assertIsString($ctag);
        } else {
            $this->assertNull($ctag);
        }
    }

    /**
     * @param TestAddressbook $cfg
     * @dataProvider addressbookProvider
     */
    public function testCanInsertValidCard(string $abookname, array $cfg): void
    {
        $abook = TIS::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        $vcard = TestInfrastructure::createVCard();
        // delete the UID - createCard should create one by itself
        unset($vcard->UID);

        $createResult = $abook->createCard($vcard);
        $createResult["vcard"] = $vcard;
        self::$insertedCards[$abookname] = $createResult;
    }

    /**
     * @param TestAddressbook $cfg
     * @depends testCanInsertValidCard
     * @dataProvider addressbookProvider
     */
    public function testCanRetrieveCreatedCard(string $abookname, array $cfg): void
    {
        $abook = TIS::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->assertArrayHasKey($abookname, self::$insertedCards);
        [ 'uri' => $cardUri, 'etag' => $cardETag, 'vcard' => $vcard ] = self::$insertedCards[$abookname];

        [ 'etag' => $etagGet, 'vcard' => $vcardGet ] = $abook->getCard($cardUri);

        // store ETag for the updateCard tests
        self::$insertedCards[$abookname]['etag'] = $etagGet;

        // insertCards etag return is optional
        if (!empty($cardETag)) {
            $this->assertSame($cardETag, $etagGet);
        }

        TestInfrastructure::compareVCards($vcard, $vcardGet, true);

        // retrieve card raw using downloadResource()
        [ 'body' => $vcfString ] = $abook->downloadResource($cardUri);
        $vcardRaw = \Sabre\VObject\Reader::read($vcfString);
        $this->assertInstanceOf(VCard::class, $vcardRaw);
        TestInfrastructure::compareVCards($vcardGet, $vcardRaw, true);
    }

    /**
     * Since it happens nowhere in the tests, we test that WebDavResource::createInstance() properly
     * creates a WebDavResource instance for an addressbook object (which is not a collection).
     *
     * @param TestAddressbook $cfg
     * @depends testCanInsertValidCard
     * @dataProvider addressbookProvider
     */
    public function testCanCreateWebDavResourceForNonCollection(string $abookname, array $cfg): void
    {
        $abook = TIS::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->assertArrayHasKey($abookname, self::$insertedCards);
        $cardUri = self::$insertedCards[$abookname]['uri'];
        $cardUri = TestInfrastructure::normalizeUri($abook, $cardUri);

        $resource = WebDavResource::createInstance($cardUri, $abook->getAccount());
        $this->assertSame(WebDavResource::class, get_class($resource));

        // test getBasename reports vcard filename
        $expName = preg_replace('/^.*\//', '', $cardUri);
        $this->assertSame($expName, $resource->getBasename());

        // test jsonSerialize on WebDavResource yields an array containing the URL
        $json = $resource->jsonSerialize();
        $this->assertSame(['uri' => $cardUri], $json);
    }

    /**
     * @param TestAddressbook $cfg
     * @depends testCanInsertValidCard
     * @depends testCanRetrieveCreatedCard
     * @dataProvider addressbookProvider
     */
    public function testUpdateFailsWithErroneousCard(string $abookname, array $cfg): void
    {
        $abook = TIS::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->assertArrayHasKey($abookname, self::$insertedCards);
        [ 'uri' => $cardUri, 'etag' => $cardETag, 'vcard' => $vcard ] = self::$insertedCards[$abookname];

        $this->assertNotEmpty($cardETag);
        // make sure changes do not affect subsequent tests
        $vcardCopy = clone $vcard;
        $vcardCopy->VERSION = '2.1'; // not allowed for CardDAV

        try {
            $abook->updateCard($cardUri, $vcardCopy, $cardETag);
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("CardDAV servers are not allowed to accept vCard 2.1", $e->getMessage());
            TestInfrastructure::logger()->expectMessage('error', 'Issue with provided VCard');
            return;
        }

        $this->assertFalse(true, "Expected InvalidArgumentException not thrown");
    }

    /**
     * @param TestAddressbook $cfg
     * @depends testCanInsertValidCard
     * @depends testCanRetrieveCreatedCard
     * @dataProvider addressbookProvider
     */
    public function testCanUpdateCreatedCard(string $abookname, array $cfg): void
    {
        $abook = TIS::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->assertArrayHasKey($abookname, self::$insertedCards);
        [ 'uri' => $cardUri, 'etag' => $cardETag, 'vcard' => $vcard ] = self::$insertedCards[$abookname];

        $this->assertNotEmpty($cardETag);
        $vcard->NOTE = 'First update';
        $etagUpdated = $abook->updateCard($cardUri, $vcard, $cardETag);
        $this->assertNotNull($etagUpdated); // null means update failed

        [ 'etag' => $etagGet, 'vcard' => $vcardGet ] = $abook->getCard($cardUri);


        // insertCards etag return is optional
        if (strlen($etagUpdated) != 0) {
            $this->assertSame($etagUpdated, $etagGet);
        }
        TestInfrastructure::compareVCards($vcard, $vcardGet, true);
    }

    /**
     * @param TestAddressbook $cfg
     * @depends testCanUpdateCreatedCard
     * @dataProvider addressbookProvider
     */
    public function testUpdateOfOutdatedCardFails(string $abookname, array $cfg): void
    {
        if (TIS::hasFeature($abookname, TIS::BUG_ETAGPRECOND_NOTCHECKED)) {
            $this->markTestSkipped("$abookname has a bug: If-Match ETag precondition is ignored by server");
        }

        $abook = TIS::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->assertArrayHasKey($abookname, self::$insertedCards);
        [ 'uri' => $cardUri, 'etag' => $cardETag, 'vcard' => $vcard ] = self::$insertedCards[$abookname];

        $this->assertNotEmpty($cardETag);
        $vcard->NOTE = 'Second update';
        // the etag is still from the initial insert, missing the update done in testCanUpdateCreatedCard
        $etagUpdated = $abook->updateCard($cardUri, $vcard, $cardETag);
        $this->assertNull($etagUpdated, "update should have failed with status 412, but did not");
    }

    /**
     * @param TestAddressbook $cfg
     * @depends testCanInsertValidCard
     * @dataProvider addressbookProvider
     */
    public function testCanDeleteExistingCard(string $abookname, array $cfg): void
    {
        $abook = TIS::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->assertArrayHasKey($abookname, self::$insertedCards);
        [ 'uri' => $cardUri ] = self::$insertedCards[$abookname];
        unset(self::$insertedCards[$abookname]);

        $abook->deleteCard($cardUri);

        try {
            $abook->getCard($cardUri);
            $this->assertFalse(true, "Deleted card could be retrieved without expected exception: $cardUri");
        } catch (\Exception $e) {
            $this->assertMatchesRegularExpression("/HTTP.*404/", $e->getMessage());
        }
    }

    /**
     * Tests that a card with minor/repairable issues (missing FN) can be inserted successfully.
     *
     * @param TestAddressbook $cfg
     * @depends testCanInsertValidCard
     * @depends testCanDeleteExistingCard
     * @dataProvider addressbookProvider
     */
    public function testCanInsertCardWithMinorProblems(string $abookname, array $cfg): void
    {
        $abook = TIS::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        $vcard = TestInfrastructure::createVCard();
        // delete the FN - should be recreated from FN by createCard
        unset($vcard->FN);

        $createResult = $abook->createCard($vcard);
        // clean up
        $abook->deleteCard($createResult['uri']);

        TestInfrastructure::logger()->expectMessage('warning', 'Issue with provided VCard');
    }

    /**
     * @param TestAddressbook $cfg
     * @dataProvider addressbookProvider
     */
    public function testGetDetailsProvidesCoreInformation(string $abookname, array $cfg): void
    {
        $abook = TIS::getAddressbook($abookname);
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        $details = $abook->getDetails();
        $expAbName = $abook->getName();
        $this->assertStringContainsString($expAbName, $details, "Displayname not contained in details");
        $this->assertStringContainsString($cfg["url"], $details, "URI not contained in details");

        $chkFeatures = [
            [ TIS::FEAT_SYNCCOLL, "{DAV:}sync-collection" ],
            [ TIS::FEAT_MULTIGET, "{CARDDAV}addressbook-multiget" ],
            [ TIS::FEAT_CTAG, "{CS}getctag" ],
        ];

        foreach ($chkFeatures as $feat) {
            if (TIS::hasFeature($abookname, $feat[0])) {
                $this->assertStringContainsString($feat[1], $details, "$feat[1] missing from details");
            }
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
