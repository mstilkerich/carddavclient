<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Interop;

use MStilkerich\Tests\CardDavClient\TestInfrastructure;
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;

final class AddressbookCollectionTest extends TestCase
{
    /**
     * @var array<string, array{vcard: VCard, uri: string, etag: string}>
     *    Cards inserted to addressbooks by tests in this class. Maps addressbook name to an associative array.
     */
    private static $insertedCards;

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

    public function addressbookProvider(): array
    {
        return TestInfrastructureSrv::addressbookProvider();
    }

    /** @dataProvider addressbookProvider */
    public function testPropertiesCorrectlyReported(string $abookname, array $cfg): void
    {
        $abook = TestInfrastructureSrv::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        $this->assertSame($cfg["displayname"], $abook->getName(), "Displayname");
        $this->assertSame(
            TestInfrastructureSrv::hasFeature($abookname, TestInfrastructureSrv::FEAT_SYNCCOLL),
            $abook->supportsSyncCollection(),
            "SyncCollection support"
        );
        $this->assertSame(
            TestInfrastructureSrv::hasFeature($abookname, TestInfrastructureSrv::FEAT_MULTIGET),
            $abook->supportsMultiGet(),
            "MultiGet report"
        );

        $ctag = $abook->getCTag();
        if (TestInfrastructureSrv::hasFeature($abookname, TestInfrastructureSrv::FEAT_CTAG)) {
            $this->assertIsString($ctag);
        } else {
            $this->assertNull($ctag);
        }
    }

    /** @dataProvider addressbookProvider */
    public function testCanInsertValidCard(string $abookname, array $cfg): void
    {
        $abook = TestInfrastructureSrv::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        $vcard = TestInfrastructure::createVCard();
        $createResult = $abook->createCard($vcard);
        $createResult["vcard"] = $vcard;
        self::$insertedCards[$abookname] = $createResult;
    }

    /**
     * @depends testCanInsertValidCard
     * @dataProvider addressbookProvider
     */
    public function testCanRetrieveCreatedCard(string $abookname, array $cfg): void
    {
        $abook = TestInfrastructureSrv::$addressbooks[$abookname];
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
     * @depends testCanRetrieveCreatedCard
     * @dataProvider addressbookProvider
     */
    public function testCanDeleteExistingCard(string $abookname, array $cfg): void
    {
        $abook = TestInfrastructureSrv::$addressbooks[$abookname];
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
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
