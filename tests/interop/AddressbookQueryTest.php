<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Interop;

use MStilkerich\Tests\CardDavClient\TestInfrastructure;
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavClient\XmlElements\Filter;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;
use MStilkerich\Tests\CardDavClient\Interop\TestInfrastructureSrv as TIS;

/**
 * @psalm-import-type SimpleConditions from Filter
 * @psalm-import-type ComplexConditions from Filter
 * @psalm-import-type TestAddressbook from TestInfrastructureSrv
 */
final class AddressbookQueryTest extends TestCase
{
    /**
     * @var array<string, list<array{vcard: VCard, uri: string, etag: string}>>
     *    Cards inserted to addressbooks by tests in this class. Maps addressbook name to list of associative arrays.
     */
    private static $insertedCards;

    public static function setUpBeforeClass(): void
    {
        TIS::init();
        self::$insertedCards = [];
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
        // delete cards
        foreach (self::$insertedCards as $abookname => $cards) {
            $abook = TIS::$addressbooks[$abookname];
            TestCase::assertInstanceOf(AddressbookCollection::class, $abook);

            foreach ($cards as $card) {
                $abook->deleteCard($card['uri']);
            }
        }
    }

    /** @return array<string, array{string, TestAddressbook}> */
    public function addressbookProvider(): array
    {
        return TIS::addressbookProvider();
    }

    /** @return array<string, array{string, SimpleConditions, list<int>}> */
    public function simpleQueriesProvider(): array
    {
        /*
         * Test data set:
         * NICK john0doe, EMAIL john0doe@example.com doe0@example.com, IMPP Jabber
         * NICK john1doe, EMAIL john1doe@sub.example.com
         * NICK john2doe, EMAIL john2doe@smth.else
         * NICK john3doe, EMAIL john3doe@example.com doe3@example.com, IMPP Skype
         * NICK john4doe, EMAIL john4doe@sub.example.com
         * NICK john5doe, EMAIL john5doe@smth.else
         * NICK john6doe, EMAIL john6doe@example.com doe6@example.com, IMPP Jabber
         * NICK john7doe, EMAIL john7doe@sub.example.com
         * NICK john8doe, EMAIL john8doe@smth.else
         * NICK john9doe, EMAIL john9doe@example.com doe9@example.com, IMPP Skype
         */
        $datasets = [
            'EmailEquals' => [ ['EMAIL' => '/doe6@example.com/='], [ 6 ] ],
            'EmailContains' => [ ['EMAIL' => '/6doe@exa/'], [ 6 ] ],
            'EmailStartsWith' => [ ['EMAIL' => '/doe6/^'], [ 6 ] ],
            'EmailEndsWith' => [ ['EMAIL' => '/@smth.else/$'], [ 2, 5, 8 ] ],
            'EmailContainsNot' => [ ['EMAIL' => '!/@example.com/'], [ 1, 2, 4, 5, 7, 8 ] ],
            'HasNoIMPP' => [ ['IMPP' => null], [ 1, 2, 4, 5, 7, 8 ] ],
            'HasIMPP' => [ ['IMPP' => '//'], [ 0, 3, 6, 9 ] ],
//            'ParamMatchExactly' => [ ['EMAIL' => ['TYPE', '/HOME/=']], [ 0, 2, 4, 6, 8 ] ],
//            'ParamNotDefined' => [ ['EMAIL' => ['TYPE', null]], [ 0, 3, 6, 9 ] ],
        ];

        $abooks = TIS::addressbookProvider();
        $ret = [];

        foreach (array_keys($abooks) as $abookname) {
            foreach ($datasets as $dsname => $ds) {
                $ret["$dsname ($abookname)"] = array_merge([$abookname], $ds);
            }
        }

        return $ret;
    }

    /**
     * @param string $abookname Name of the addressbook to test with
     * @param SimpleConditions $conditions The conditions to pass to the query operation
     * @param list<int> $expCards A list of expected cards, given by their index in self::$insertedCards[$abookname]
     * @dataProvider simpleQueriesProvider
     */
    public function testQueryBySimpleConditions(string $abookname, array $conditions, array $expCards): void
    {
        $abook = $this->createSamples($abookname);
        $result = $abook->query($conditions);
        $this->checkExpectedCards($abookname, $result, $expCards);
    }

    /**
     * @param string $abookname Name of the addressbook to test with
     * @param TestAddressbook $cfg
     * @dataProvider addressbookProvider
     */
    public function testQueryForParameterValue(string $abookname, array $cfg): void
    {
        if (!TIS::hasFeature($abookname, TIS::FEAT_PARAMFILTER)) {
            $this->markTestSkipped("$abookname does not support param-filter");
        } elseif (TIS::hasFeature($abookname, TIS::BUG_PARAMFILTER_ON_NONEXISTENT_PARAM)) {
            $this->markTestSkipped("$abookname has a bug concerning handling of param-filter");
        } else {
            $abook = $this->createSamples($abookname);
            $result = $abook->query(['EMAIL' => ['TYPE', '/HOME/=']]);
            $this->checkExpectedCards($abookname, $result, [ 0, 2, 4, 6, 8 ]);
        }
    }

    /**
     * Checks that a result returned by the query matches the expected cards.
     *
     * @param array<string, array{vcard: VCard, etag: string}> $result
     * @param list<int> $expCardIdxs List of indexes into self::$insertedCards with the cards that are expected in the
     *                               result
     */
    private function checkExpectedCards(string $abookname, array $result, array $expCardIdxs): void
    {
        // remove cards not created by this test (may exist on server and match query filters)
        $knownUris = array_column(self::$insertedCards[$abookname], 'uri');
        foreach (array_keys($result) as $uri) {
            if (!in_array($uri, $knownUris)) {
                unset($result[$uri]);
            }
        }

        $this->assertCount(count($expCardIdxs), $result, "Unexpected number of results");
        foreach ($expCardIdxs as $idx) {
            $expCard = self::$insertedCards[$abookname][$idx];
            $this->assertArrayHasKey($expCard["uri"], $result);
            $rcvCard = $result[$expCard["uri"]];
            TestInfrastructure::compareVCards($expCard["vcard"], $rcvCard["vcard"], true);
        }
    }

    private function createSamples(string $abookname): AddressbookCollection
    {
        $abook = TIS::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        if (isset(self::$insertedCards[$abookname])) {
            return $abook;
        }

        self::$insertedCards[$abookname] = [];

        $domains = [ "example.com", "sub.example.com", "smth.else" ];
        // Google only supports home and work for type, everything else must be in X-ABLabel
        // Otherwise it will simply strip the type. This is though even RFC 2426 explicitly allows non-standard values
        // for the EMAIL TYPE.
        // Furthermore, it will convert home and work to uppercase spelling, so for our comparisons to work, we need to
        // use uppercase here.
        $types = [ "HOME", "WORK" ];
        $impptypes = [ ["Jabber", "xmpp"], ["Skype", "skype"] ];

        // create cards
        for ($i = 0; $i < 10; ++$i) {
            $domain = $domains[$i % count($domains)];
            $type = $types[$i % count($types)];

            $vcard = TestInfrastructure::createVCard();
            $vcard->NICKNAME = "john{$i}doe";
            $vcard->add('EMAIL', "john{$i}doe@$domain", ['type' => $type]);

            //$email2 = "";
            if (($i % 3) == 0) {
                [ $imppType, $imppProto ] = $impptypes[$i % count($impptypes)];
                $vcard->add('EMAIL', "doe{$i}@$domain"); // no TYPE param
                $vcard->add('IMPP', "$imppProto:jab{$i}@$domain", ['X-SERVICE-TYPE' => $imppType, 'TYPE' => 'HOME']);
                //$email2 = "doe{$i}@$domain";
            }

            //echo "NICK john{$i}doe, EMAIL john{$i}doe@$domain $email2\n";

            $createResult = $abook->createCard($vcard);
            $createResult["vcard"] = $vcard;
            $createResult["uri"] = TestInfrastructure::getUriPath($createResult["uri"]);
            self::$insertedCards[$abookname][] = $createResult;
        }

        return $abook;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
