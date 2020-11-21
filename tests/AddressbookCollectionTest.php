<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient;

use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;

final class AddressbookCollectionTest extends TestCase
{
    /**
     * @var array $insertedCards Cards inserted to addressbooks by tests in this class
     *    Maps addressbook name to an associative array. Each associative array contains
     *    the keys etag (may be null), uri and vcard with the corresponding values.
     */
    private static $insertedCards;

    public static function setUpBeforeClass(): void
    {
        AccountData::init();
    }

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public static function tearDownAfterClass(): void
    {
    }

    public function addressbookProvider(): array
    {
        return AccountData::addressbookProvider();
    }

    /** @dataProvider addressbookProvider */
    public function testPropertiesCorrectlyReported(string $abookname, array $cfg): void
    {
        $abook = AccountData::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        $this->assertEquals($cfg["displayname"], $abook->getName(), "Displayname");
        $this->assertEquals($cfg["supports_synccoll"], $abook->supportsSyncCollection(), "SyncCollection support");
        $this->assertEquals($cfg["supports_multiget"], $abook->supportsMultiGet(), "MultiGet report");
        //$this->assertEquals($cfg["supports_ctag"], $abook->getCTag(), "CTag");
    }

    /** @dataProvider addressbookProvider */
    public function testCanInsertValidCard(string $abookname, array $cfg): void
    {
        $abook = AccountData::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        $vcard = TestUtils::createVCard();
        $createResult = $abook->createCard($vcard);
        $createResult["vcard"] = $vcard;

        if (!isset(self::$insertedCards[$abookname])) {
            self::$insertedCards[$abookname] = [];
        }
        self::$insertedCards[$abookname] = $createResult;
    }

    /**
     * @depends testCanInsertValidCard
     * @dataProvider addressbookProvider
     */
    public function testCanRetrieveCreatedCard(string $abookname, array $cfg): void
    {
        $abook = AccountData::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->assertArrayHasKey($abookname, self::$insertedCards);
        [ 'uri' => $cardUri, 'etag' => $cardETag, 'vcard' => $vcard ] = self::$insertedCards[$abookname];

        [ 'etag' => $etagGet, 'vcard' => $vcardGet ] = $abook->getCard($cardUri);

        // insertCards etag return is optional
        if (!empty($cardETag)) {
            $this->assertEquals($cardETag, $etagGet);
        }

        TestUtils::compareVCard($vcard, $vcardGet);
    }

    /**
     * @depends testCanRetrieveCreatedCard
     * @dataProvider addressbookProvider
     */
    public function testCanDeleteExistingCard(string $abookname, array $cfg): void
    {
        $abook = AccountData::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->assertArrayHasKey($abookname, self::$insertedCards);
        [ 'uri' => $cardUri, 'etag' => $cardETag, 'vcard' => $vcard ] = self::$insertedCards[$abookname];
        unset(self::$insertedCards[$abookname]);

        $abook->deleteCard($cardUri);

        try {
            $abook->getCard($cardUri);
            $this->assertFalse(true, "Deleted card could be retrieved without expected exception: $cardUri");
        } catch (\Exception $e) {
            $this->assertMatchesRegularExpression("/HTTP.*404/", $e->getMessage());
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
