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
     * The sample cards.
     *
     * For TYPE attributes, we should only use HOME and WORK in that spelling, because Google drops everything it does
     * not know and changes the spelling of these two to uppercase.
     *
     * For IMPP, we can use X-SERVICE-TYPE=Jabber/Skype with schemes xmpp/skype, again in that spelling.
     * @var list<list<array{string, string, array<string, string|list<string>>}>>
     */
    private const SAMPLES = [
        // properties are added to prepared cards from TestInfrastructure::createVCard()
        // We add a NICKNAME Jonny$i to each card to easy map log entries back to this array
        [ // card 0
            [ 'EMAIL', 'doe@big.corp', ['TYPE' => 'WORK'] ],
            [ 'EMAIL', 'johndoe@example.com', ['TYPE' => 'HOME'] ],
            [ 'X-CUSTOMPROP', 'foobar', ['X-CUSTOMPARAM' => 'WORK'] ],
        ],
        [ // card 1 - custom property with a custom parameter (to avoid Google messing with its content)
            [ 'EMAIL', 'maxmu@abcd.com', [] ],
            [ 'X-CUSTOMPROP', 'foobar', [ 'X-CUSTOMPARAM' => ['HOME', 'WORK'], 'X-SPACEPARAM' => "HELLO, WORLD" ] ],
        ],
        [ // card 2 - no EMAIL property
            [ 'TEL', '12345', ['TYPE' => 'HOME'] ],
            [ 'TEL', '555', [] ],
        ],
        [ // card 3
            [ 'item1.EMAIL', 'foo@ex.com', [] ],
            [ 'item1.X-ABLABEL', 'CustomLabel', [] ],
            [ 'IMPP', 'xmpp:foo@example.com', ['X-SERVICE-TYPE' => 'Jabber', 'TYPE' => 'HOME'] ],
        ],
    ];

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
            $abook = TIS::getAddressbook($abookname);
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
            'HasEmail' => [ ['EMAIL' => '//'], [ 0, 1, 3 ], 0, 0 ],
            // check that property names are treated case insensitive
            'HasNoEmailDiffCase' => [ ['email' => null], [ 2 ], TIS::BUG_CASESENSITIVE_NAMES, 0 ],
            'HasEmailDiffCase' => [ ['email' => '//'], [ 0, 1, 3 ], TIS::BUG_CASESENSITIVE_NAMES, 0 ],

            // simple text matches against property values
            'EmailEquals' => [ ['EMAIL' => '/johndoe@example.com/='], [ 0 ], 0, 0 ],
            'EmailContains' => [ ['EMAIL' => '/mu@ab/'], [ 1 ], 0, 0 ],
            'EmailStartsWith' => [ ['EMAIL' => '/max/^'], [ 1 ], 0, 0 ],
            'EmailEndsWith' => [ ['EMAIL' => '/@example.com/$'], [ 0 ], 0, 0 ],
            // check matching is case insensitive
            'EmailEqualsDiffCase' => [ ['EMAIL' => '/johNDOE@EXAmple.com/='], [ 0 ], 0, 0 ],
            'EmailContainsDiffCase' => [ ['EMAIL' => '/MU@ab/'], [ 1 ], 0, 0 ],
            'EmailStartsWithDiffCase' => [ ['EMAIL' => '/MAX/^'], [ 1 ], 0, 0 ],
            'EmailEndsWithDiffCase' => [ ['EMAIL' => '/@EXAmple.com/$'], [ 0 ], 0, 0 ],

            // simple text matches with negated match behavior
            // Case 1: Either all or no EMAIL properties match the negated filter
            'EmailEndsNotWith' => [
                ['EMAIL' => '!/@abcd.com/$'],
                [ 0, 3 ],
                TIS::BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS,
                0
            ],
            // Case 2: Some, but not all EMAIL properties match the negated filter
            'EmailContainsNotSome' => [
                ['EMAIL' => '!/@example.com/'],
                [ 0, 1, 3 ],
                TIS::BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS | TIS::BUG_INVTEXTMATCH_SOMEMATCH,
                0
            ],

            // test whether property with parameter defined or not defined exists
            'ParamNotDefined' => [
                ['EMAIL' => ['TYPE', null]],
                [ 1, 3 ],
                TIS::BUG_PARAMNOTDEF_MATCHES_UNDEF_PROPS,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamDefined' => [
                ['EMAIL' => ['TYPE', '//']],
                [ 0 ],
                TIS::BUG_PARAMDEF,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamNotDefinedDiffCase' => [
                ['EMAIL' => ['type', null]],
                [ 1, 3 ],
                TIS::BUG_PARAMNOTDEF_MATCHES_UNDEF_PROPS | TIS::BUG_CASESENSITIVE_NAMES,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamDefinedDiffCase' => [
                ['EMAIL' => ['type', '//']],
                [ 0 ],
                TIS::BUG_PARAMDEF | TIS::BUG_CASESENSITIVE_NAMES,
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
            'ParamEquals' => [
                ['EMAIL' => ['TYPE', '/HOME/=']],
                [ 0 ],
                TIS::BUG_PARAMTEXTMATCH_BROKEN,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamContains' => [
                ['EMAIL' => ['TYPE', '/ORK/']],
                [ 0 ],
                TIS::BUG_PARAMTEXTMATCH_BROKEN,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamStartsWith' => [
                ['EMAIL' => ['TYPE', '/WOR/^']],
                [ 0 ],
                TIS::BUG_PARAMTEXTMATCH_BROKEN,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamEndsWith' => [
                ['EMAIL' => ['TYPE', '/ORK/$']],
                [ 0 ],
                TIS::BUG_PARAMTEXTMATCH_BROKEN,
                TIS::FEAT_PARAMFILTER
            ],
            // check matching is case insensitive
            'ParamEqualsParamNameDiffCase' => [
                ['EMAIL' => ['type', '/HOME/=']],
                [ 0 ],
                TIS::BUG_PARAMTEXTMATCH_BROKEN | TIS::BUG_CASESENSITIVE_NAMES,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamEqualsDiffCase' => [
                ['EMAIL' => ['TYPE', '/hoME/=']],
                [ 0 ],
                TIS::BUG_PARAMTEXTMATCH_BROKEN,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamContainsDiffCase' => [
                ['EMAIL' => ['TYPE', '/orK/']],
                [ 0 ],
                TIS::BUG_PARAMTEXTMATCH_BROKEN,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamStartsWithDiffCase' => [
                ['EMAIL' => ['TYPE', '/woR/^']],
                [ 0 ],
                TIS::BUG_PARAMTEXTMATCH_BROKEN,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamEndsWithDiffCase' => [
                ['EMAIL' => ['TYPE', '/orK/$']],
                [ 0 ],
                TIS::BUG_PARAMTEXTMATCH_BROKEN,
                TIS::FEAT_PARAMFILTER
            ],

            // simple negated text matches against parameter values
            // Note: param-filter does not match if the parameter does not exist
            'ParamContainsNotT' => [ // simple case: only one property instance that matches inverted filter
                ['IMPP' => ['X-SERVICE-TYPE', '!/Skype/']],
                [ 3 ],
                TIS::BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamContainsNotF' => [ // simple case: no property instance that matches inverted filter
                ['IMPP' => ['X-SERVICE-TYPE', '!/Jabber/']],
                [ ],
                TIS::BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamContainsNotSomeDiff' => [ // some properties, but not all match the inverted filter
                ['EMAIL' => ['TYPE', '!/WORK/']],
                [ 0 ],
                TIS::BUG_INVTEXTMATCH_SOMEMATCH | TIS::BUG_INVTEXTMATCH_MATCHES_UNDEF_PARAMS,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamContainsNotSomeUndef' => [ // no param matches the inverted filter, but there is one without the param
                ['TEL' => ['TYPE', '!/HOME/']],
                [ ],
                TIS::BUG_INVTEXTMATCH_MATCHES_UNDEF_PARAMS,
                TIS::FEAT_PARAMFILTER
            ],

            // tests on parameter with multiple values
            'MultivalueParamContains1' => [
                ['X-CUSTOMPROP' => ['X-CUSTOMPARAM', '/HOME/']],
                [ 1 ],
                0,
                TIS::FEAT_PARAMFILTER
            ],
            'MultivalueParamContains2' => [
                ['X-CUSTOMPROP' => ['X-CUSTOMPARAM', '/WORK/']],
                [ 0, 1 ],
                0,
                TIS::FEAT_PARAMFILTER
            ],
            'MultivalueParamEquals' => [
                ['X-CUSTOMPROP' => ['X-CUSTOMPARAM', '/HOME/=']],
                [ 1 ],
                TIS::BUG_MULTIPARAM_NOINDIVIDUAL_MATCH,
                TIS::FEAT_PARAMFILTER
            ],
            'ParamWithSpaceAndComma' => [
                ['X-CUSTOMPROP' => ['X-SPACEPARAM', '/lo, W/']],
                [ 1 ],
                TIS::BUG_PARAMCOMMAVALUE,
                TIS::FEAT_PARAMFILTER
            ],

            // tests on properties with group
            'HasNoGrpEmail' => [ ['item1.EMAIL' => null], [ 0, 1, 2 ], TIS::BUG_HANDLE_PROPGROUPS_IN_QUERY, 0 ],
            'HasGrpEmail' => [ ['item1.EMAIL' => '//'], [ 3 ], TIS::BUG_HANDLE_PROPGROUPS_IN_QUERY, 0 ],
            'GrpEmailEquals' => [ ['item1.EMAIL' => '/foo@ex.com/='], [ 3 ], TIS::BUG_HANDLE_PROPGROUPS_IN_QUERY, 0 ],
            // must not match card 0
            'GrpEmailContains' => [ ['item1.EMAIL' => '/@ex/'], [ 3 ], TIS::BUG_HANDLE_PROPGROUPS_IN_QUERY, 0 ],
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

    /** @return array<string, array{string, bool, SimpleConditions | ComplexConditions, list<int>, int, int}> */
    public function multiConditionQueriesProvider(): array
    {
        // Try to have at least one matching and one non-matching card in the result for each filter
        // Some service return an empty result or the entire addressbook without error if they do not support a filter,
        // so we will notice if we stick to that rule.
        $datasets = [
            // test whether a property is defined / not defined
            'HasTelOrIMPP' => [ false, ['TEL' => '//', 'IMPP' => '//' ], [ 2, 3 ], 0, 0 ],
            'HasEmailAndIMPP' => [ true, ['EMAIL' => '//', 'IMPP' => '//' ], [ 3 ], 0, TIS::FEAT_FILTER_ALLOF ],

            // multiple conditions in the same prop-filter
            // this one matches on the same property instance johndoe@example.com
            'TwoEmailConditionsAnd' => [ false, [ ['EMAIL', ['/doe/', '/.com/$', 'matchAll' => true]] ], [ 0 ], 0, 0 ],
            'TwoEmailConditionsOr' => [
                false,
                [ ['EMAIL', ['/doe/^', '/abcd.com/$']] ],
                [ 0, 1 ],
                TIS::BUG_PROPFILTER_ALLOF,
                0
            ],
            'TwoPropFiltersConditionsOr' => [
                false,
                [ ['EMAIL', ['/doe/^']], ['EMAIL', ['/abcd.com/$']] ],
                [ 0, 1 ],
                0,
                0,
            ],
            'TwoPropFiltersConditionsAnd' => [
                true,
                [ ['EMAIL', ['/doe/^']], ['EMAIL', ['/.com/$']] ],
                [ 0 ],
                0,
                TIS::FEAT_FILTER_ALLOF,
            ],
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
     * @param bool $matchAll Whether all or any condition needs to match
     * @param SimpleConditions|ComplexConditions $conditions The conditions to pass to the query operation
     * @param list<int> $expCards A list of expected cards, given by their index in self::$insertedCards[$abookname]
     * @param int $inhibitingBugs A mask with bug flags where this test should be skipped
     * @param int $featuresNeeded A mask with server features required for the test.
     * @dataProvider multiConditionQueriesProvider
     */
    public function testQueryByMultipleConditions(
        string $abookname,
        bool $matchAll,
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
            $result = $abook->query($conditions, [], $matchAll);
            $this->checkExpectedCards($abookname, $result, $expCards);
        }
    }

    /**
     * Tests the behavior of evaluation of multiple conditions inside one prop-filter with allof (AND) behavior.
     * There is two behaviors found in the wild if there are multiple values for the property that prop-filter matches
     * on:
     * 1) Each of the conditions can be satisfied by any value of the property
     * 2) All of the conditions must be satisfied by one value of the property
     *
     * RFC 6352 is not really clear on what is correct here, although I cannot think of an example where 1) would be
     * desired.
     *
     * @param TestAddressbook $cfg
     * @dataProvider addressbookProvider
     */
    public function testAllOfPropFilterAppliesToSamePropertyValue(string $abookname, array $cfg): void
    {
        if (TIS::hasFeature($abookname, TIS::FEAT_ALLOF_SINGLEPROP)) {
            $expCards = [];
        } else {
            $expCards = [ 0 ];
        }
        $abook = $this->createSamples($abookname);

        // #27: Have one test that tries a query report on a fresh AddressbookCollection object, so that the underlying
        // HttpClientAdapter has not negotiated the authentication scheme yet
        $abook = new AddressbookCollection($abook->getUri(), $abook->getAccount());

        $result = $abook->query([['EMAIL', ['/doe/^', '/.com/$', 'matchAll' => true]]], [], false);
        $this->checkExpectedCards($abookname, $result, $expCards);
    }

    /**
     * Tests limiting the amount of results returned for an addressbook-query report.
     *
     * @param TestAddressbook $cfg
     * @dataProvider addressbookProvider
     */
    public function testQueryWithLimitedResultsIfSupported(string $abookname, array $cfg): void
    {
        $abook = $this->createSamples($abookname);
        $numTestCards = count(self::$insertedCards[$abookname]);

        for ($i = 1; $i < 4; ++$i) {
            $result = $abook->query(['NICKNAME' => '/Jonny/^'], [], false, $i);
            // Limiting results is an optional feature not supported by all servers; if not supported,
            // we will get all cards of the test set
            $expCount = TIS::hasFeature($abookname, TIS::FEAT_RESULTLIMIT) ? $i : $numTestCards;
            $this->assertCount($expCount, $result, "Expected $expCount cards in result");
        }
    }

    /**
     * Tests partial retrieval of VCards.
     *
     * Not all servers supports this, this test verifies our assumptions. For servers where we know support is missing,
     * the test checks that the full vcards are returned (i.e. if the feature becomes supported at some time, the test
     * will fail and we will know about the new feature).
     *
     * @param TestAddressbook $cfg
     * @dataProvider addressbookProvider
     */
    public function testQueryWithPartialAddressDataIfSupported(string $abookname, array $cfg): void
    {
        $abook = $this->createSamples($abookname);
        $numTestCards = count(self::$insertedCards[$abookname]);

        $result = $abook->query(['NICKNAME' => '/Jonny/^'], ['EMAIL', 'TEL']);
        $result = $this->filterReceivedCards($abookname, $result);
        $this->assertCount($numTestCards, $result, "Expected $numTestCards cards in result");

        if (TIS::hasFeature($abookname, TIS::FEAT_ABOOKQUERY_PARTIALCARDS)) {
            foreach ($result as $r) {
                $this->assertNull($r['vcard']->NICKNAME, "Result card contains unrequested NICKNAME property");
            }
        } else {
            // if server does not support partial address data, check that we received the full cards
            $this->checkExpectedCards($abookname, $result, [0, 1, 2, 3]);
        }
    }

    /**
     * Removes cards not created by this test (may exist on server and match query filters)
     *
     * @param array<string, array{vcard: VCard, etag: string}> $result
     * @return array<string, array{vcard: VCard, etag: string}>
     */
    private function filterReceivedCards(string $abookname, array $result): array
    {
        // remove cards not created by this test (may exist on server and match query filters)
        $knownUris = array_column(self::$insertedCards[$abookname], 'uri');
        foreach (array_keys($result) as $uri) {
            if (!in_array($uri, $knownUris)) {
                unset($result[$uri]);
            }
        }

        return $result;
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
        $result = $this->filterReceivedCards($abookname, $result);

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

    private function createSamples(string $abookname): AddressbookCollection
    {
        $abook = TIS::getAddressbook($abookname);
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
