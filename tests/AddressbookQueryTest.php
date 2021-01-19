<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient;

use MStilkerich\CardDavClient\{Account,AddressbookCollection,QueryConditions};
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;

final class AddressbookQueryTest extends TestCase
{
    /**
     * @var array<string, list<array{vcard: VCard, uri: string, etag: string}>>
     *    Cards inserted to addressbooks by tests in this class. Maps addressbook name to list of associative arrays.
     */
    private static $insertedCards;

    public static function setUpBeforeClass(): void
    {
        AccountData::init();
        self::$insertedCards = [];
    }

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public static function tearDownAfterClass(): void
    {
        // delete cards
        foreach (self::$insertedCards as $abookname => $cards) {
            $abook = AccountData::$addressbooks[$abookname];
            TestCase::assertInstanceOf(AddressbookCollection::class, $abook);

            foreach ($cards as $card) {
                $abook->deleteCard($card['uri']);
            }
        }
    }

    public function addressbookProvider(): array
    {
        return AccountData::addressbookProvider();
    }

    /** @dataProvider addressbookProvider */
    public function testQueryByExactEmailAddress(string $abookname, array $cfg): void
    {
        $abook = AccountData::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);
        $this->createSamples($abookname, $abook);

        $expCard = self::$insertedCards[$abookname][6];

        $result = $abook->query(['EMAIL' => '/^doe6@example.com$/']);
        $this->assertCount(1, $result, "Unexpected number of results");
        $this->assertArrayHasKey($expCard["uri"], $result);

        $rcvCard = $result[$expCard["uri"]];
        TestUtils::compareVCards($expCard["vcard"], $rcvCard["vcard"], true);
    }

    /*
     * Test data set:
     * NICK john0doe, EMAIL john0doe@example.com doe0@example.com
     * NICK john1doe, EMAIL john1doe@sub.example.com
     * NICK john2doe, EMAIL john2doe@smth.else
     * NICK john3doe, EMAIL john3doe@example.com doe3@example.com
     * NICK john4doe, EMAIL john4doe@sub.example.com
     * NICK john5doe, EMAIL john5doe@smth.else
     * NICK john6doe, EMAIL john6doe@example.com doe6@example.com
     * NICK john7doe, EMAIL john7doe@sub.example.com
     * NICK john8doe, EMAIL john8doe@smth.else
     * NICK john9doe, EMAIL john9doe@example.com doe9@example.com
     */

    private function createSamples(string $abookname, AddressbookCollection $abook): void
    {
        if (isset(self::$insertedCards[$abookname])) {
            return;
        }

        self::$insertedCards[$abookname] = [];

        $domains = [ "example.com", "sub.example.com", "smth.else" ];
        $types = [ "home", "work", "work2", "other" ];

        // create cards
        for ($i = 0; $i < 10; ++$i) {
            $domain = $domains[$i % count($domains)];
            $type = $types[$i % count($types)];

            $vcard = TestUtils::createVCard();
            $vcard->NICKNAME = "john{$i}doe";
            $vcard->add('EMAIL', "john{$i}doe@$domain", ['type' => $type]);

            //$email2 = "";
            if (($i % 3) == 0) {
                $vcard->add('EMAIL', "doe{$i}@$domain", ['type' => 'work2']);
                //$email2 = "doe{$i}@$domain";
            }

            //echo "NICK john{$i}doe, EMAIL john{$i}doe@$domain $email2\n";

            $createResult = $abook->createCard($vcard);
            $createResult["vcard"] = $vcard;
            $createResult["uri"] = TestUtils::getUriPath($createResult["uri"]);
            self::$insertedCards[$abookname][] = $createResult;
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
