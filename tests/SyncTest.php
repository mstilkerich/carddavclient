<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient;

use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavClient\Services\Sync;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;

final class SyncTest extends TestCase
{
    /**
     * @var array $insertedUris Uris inserted to addressbooks by tests in this class
     *    Maps addressbook name to a string[] of the URIs.
     */
    private static $insertedUris;

    public static function setUpBeforeClass(): void
    {
        self::$insertedUris = [];
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
        // try to clean up leftovers
        foreach (self::$insertedUris as $abookname => $uris) {
            $abook = AccountData::$addressbooks[$abookname];
            foreach ($uris as $uri) {
                $abook->deleteCard($uri);
            }
        }
    }

    public function addressbookProvider(): array
    {
        return AccountData::addressbookProvider();
    }

    /** @dataProvider addressbookProvider */
    public function testInitialSyncWorks(string $abookname, array $cfg): void
    {
        $abook = AccountData::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        // insert two cards we can expect to be reported by the initial sync
        $createdCards = $this->createCards($abook, $abookname, 2);
        $this->assertCount(2, $createdCards);
        $syncHandler = new SyncTestHandler($abook, true, $createdCards);
        $syncmgr = new Sync();
        $synctoken = $syncmgr->synchronize($abook, $syncHandler);
        $this->assertNotEmpty($synctoken, "Synctoken after initial sync");

        // run sync handler's verification routine after the test
        $syncHandler->testVerify();
    }

    private function createCards(AddressbookCollection $abook, string $abookname, int $num): array
    {
        $createdCards = [];
        for ($i = 0; $i < $num; ++$i) {
            $vcard = TestUtils::createVCard();
            [ 'uri' => $cardUri, 'etag' => $cardETag ] = $abook->createCard($vcard);
            $cardUri = TestUtils::normalizeUri($abook, $cardUri);
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
