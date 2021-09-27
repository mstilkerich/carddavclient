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
        $abook = TIS::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        $this->assertSame($cfg["displayname"], $abook->getName(), "Displayname");
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
        $abook = TIS::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        $vcard = TestInfrastructure::createVCard();
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
        $abook = TIS::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->assertArrayHasKey($abookname, self::$insertedCards);
        [ 'uri' => $cardUri, 'etag' => $cardETag, 'vcard' => $vcard ] = self::$insertedCards[$abookname];

        [ 'etag' => $etagGet, 'vcard' => $vcardGet ] = $abook->getCard($cardUri);

        // insertCards etag return is optional
        if (!empty($cardETag)) {
            $this->assertSame($cardETag, $etagGet);
        }

        TestInfrastructure::compareVCards($vcard, $vcardGet, true);
    }

    /**
     * @param TestAddressbook $cfg
     * @depends testCanRetrieveCreatedCard
     * @dataProvider addressbookProvider
     */
    public function testCanDeleteExistingCard(string $abookname, array $cfg): void
    {
        $abook = TIS::$addressbooks[$abookname];
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
     * @param TestAddressbook $cfg
     * @dataProvider addressbookProvider
     */
    public function testGetDetailsProvidesCoreInformation(string $abookname, array $cfg): void
    {
        $abook = TIS::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        $details = $abook->getDetails();
        $this->assertStringContainsString($cfg["displayname"], $details, "Displayname not contained in details");
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
