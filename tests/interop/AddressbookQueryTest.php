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

    /** @return array<string, array{string, SimpleConditions, list<int>, int, int}> */
    public function simpleQueriesProvider(): array
    {
        // Try to have at least one matching and one non-matching card in the result for each filter
        // Some service return an empty result or the entire addressbook without error if they do not support a filter,
        // so we will notice if we stick to that rule.
        $datasets = [
            // test whether a property is defined / not defined
            'HasNoEmail' => [ ['EMAIL' => null], [ 2 ], 0, 0 ],
            'HasEmail' => [ ['EMAIL' => '//'], [ 0, 1 ], 0, 0 ],

            // simple text matches against property values
            'EmailEquals' => [ ['EMAIL' => '/johndoe@example.com/='], [ 0 ], 0, 0 ],
            'EmailContains' => [ ['EMAIL' => '/mu@ab/'], [ 1 ], 0, 0 ],
            'EmailStartsWith' => [ ['EMAIL' => '/max/^'], [ 1 ], 0, 0 ],
            'EmailEndsWith' => [ ['EMAIL' => '/@example.com/$'], [ 0 ], 0, 0 ],

            // simple text matches with negated match behavior
            // Case 1: Either all or no EMAIL properties match the negated filter
            'EmailEndsNotWith' => [ ['EMAIL' => '!/@abcd.com/$'], [ 0 ], TIS::BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS, 0 ],
            // Case 2: Some, but not all EMAIL properties match the negated filter
            'EmailContainsNotSome' => [
                ['EMAIL' => '!/@example.com/'],
                [ 0, 1 ],
                TIS::BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS | TIS::BUG_INVTEXTMATCH_SOMEMATCH,
                0
            ],

            // test whether property with parameter defined or not defined exists
            'ParamNotDefined' => [
                ['EMAIL' => ['TYPE', null]],
                [ 1 ],
                TIS::BUG_PARAMNOTDEF_MATCHES_UNDEF_PROPS,
                TIS::FEAT_PARAMFILTER
            ],
            // property with multiple values, where one has the parameter defined, the other has not -> must not match
            'ParamNotDefinedSome' => [
                ['TEL' => ['TYPE', null]],
                [ ],
                TIS::BUG_PARAMNOTDEF_MATCHES_UNDEF_PROPS | TIS::BUG_PARAMNOTDEF_SOMEMATCH,
                TIS::FEAT_PARAMFILTER
            ],

            // simple text matches against parameter values
            'ParamMatchExactly' => [ ['EMAIL' => ['TYPE', '/HOME/=']], [ 0 ], 0, TIS::FEAT_PARAMFILTER ],
            // Cards with a TEL;HOME property match !/WORK/; TEL without TYPE param does not match
         //   'ParamInvertedMatchUnsetParam' => [['TEL' => ['TYPE', '!/WORK/']], [ 0, 3, 6, 9 ] ],
            // Cards with a EMAIL;TYPE=HOME property match !/WORK/, even if there also is en EMAIL;TYPE=WORK property
         //   'ParamInvertedMatchDiffParam' => [['EMAIL' => ['TYPE', '!/WORK/']], [ 0, 2, 4, 6, 8 ] ],
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
     * @param int $inhibitingBugs A mask with bug flags where this test should be skipped
     * @param int $featuresNeeded A mask with server features required for the test.
     * @dataProvider simpleQueriesProvider
     */
    public function testQueryBySimpleConditions(
        string $abookname,
        array $conditions,
        array $expCards,
        int $inhibitingBugs,
        int $featuresNeeded
    ): void {
        if ($inhibitingBugs != 0 && TIS::hasFeature($abookname, $inhibitingBugs)) {
            $this->markTestSkipped("$abookname has a bug that prevents successful execution of this test vector");
        } elseif ($featuresNeeded != 0 && !TIS::hasFeature($abookname, $featuresNeeded, false)) {
            $this->markTestSkipped("$abookname lacks a feature required for successful execution of this test vector");
        } else {
            $abook = $this->createSamples($abookname);
            $result = $abook->query($conditions);
            $this->checkExpectedCards($abookname, $result, $expCards);
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

        $expUris = [];
        foreach ($expCardIdxs as $idx) {
            $expCard = self::$insertedCards[$abookname][$idx];
            $this->assertArrayHasKey($expCard["uri"], $result);
            $expUris[] = $expCard["uri"];
            $rcvCard = $result[$expCard["uri"]];
            TestInfrastructure::compareVCards($expCard["vcard"], $rcvCard["vcard"], true);
        }

        foreach ($result as $uri => $res) {
            $this->assertContains($uri, $expUris, "Unexpected card in result: " . ($res["vcard"]->NICKNAME ?? ""));
        }
    }

    /**
     * The sample cards.
     *
     * For TYPE attributes, we should only use HOME and WORK in that spelling, because Google drops everything it does
     * not know and changes the spelling of these two to uppercase.
     *
     * For IMPP, we can use X-SERVICE-TYPE=Jabber/Skype with schemes xmpp/skype, again in that spelling.
     *
     * @var list<list<array{string, string, array<string,string>}>>
     */
    private const SAMPLES = [
        // properties are added to prepared cards from TestInfrastructure::createVCard()
        // We add a NICKNAME Jonny$i to each card to easy map log entries back to this array
        [ // card 0
            [ 'EMAIL', 'doe@big.corp', ['TYPE' => 'WORK'] ],
            [ 'EMAIL', 'johndoe@example.com', ['TYPE' => 'HOME'] ],
        ],
        [ // card 1
            [ 'EMAIL', 'maxmu@abcd.com', [] ],
        ],
        [ // card 2 - no EMAIL property
            [ 'TEL', '12345', ['TYPE' => 'HOME'] ],
            [ 'TEL', '555', [] ],
        ],
    ];

    private function createSamples(string $abookname): AddressbookCollection
    {
        $abook = TIS::$addressbooks[$abookname];
        $this->assertInstanceOf(AddressbookCollection::class, $abook);

        if (isset(self::$insertedCards[$abookname])) {
            return $abook;
        }

        self::$insertedCards[$abookname] = [];

        foreach (self::SAMPLES as $i => $card) {
            $vcard = TestInfrastructure::createVCard();
            $vcard->NICKNAME = "Jonny$i";

            foreach ($card as $property) {
                $vcard->add($property[0], $property[1], $property[2]);
            }

            $createResult = $abook->createCard($vcard);
            $createResult["vcard"] = $vcard;
            $createResult["uri"] = TestInfrastructure::getUriPath($createResult["uri"]);
            self::$insertedCards[$abookname][] = $createResult;
        }

        return $abook;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
