<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Interop;

use Wa72\SimpleLogger\FileLogger;
use MStilkerich\Tests\CardDavClient\TestInfrastructure;
use MStilkerich\CardDavClient\{Account,AddressbookCollection,Config};
use PHPUnit\Framework\TestCase;

/**
 * @psalm-type TestAccount = array{
 *   username: string,
 *   password: string,
 *   discoveryUri: string,
 *   syncAllowExtraChanges: bool,
 *   featureSet: int
 * }
 *
 * @psalm-type TestAddressbook = array{
 *   account: string,
 *   url: string,
 *   displayname: string,
 *   readonly?: bool
 * }
 */

final class TestInfrastructureSrv
{
    // KNOWN FEATURES AND QUIRKS OF DIFFERENT SERVICES THAT NEED TO BE CONSIDERED IN THE TESTS
    public const FEAT_SYNCCOLL = 2 ** 0;
    public const FEAT_MULTIGET = 2 ** 1;
    public const FEAT_CTAG = 2 ** 2;
    // iCloud does not support param-filter, it simply returns all cards
    public const FEAT_PARAMFILTER = 2 ** 3;
    // iCloud does not support "allof" matching at filter level (i.e. AND of multiple prop-filters)
    public const FEAT_FILTER_ALLOF = 2 ** 4;

    // This feature is set for servers that have an allof matching behavior at the prop-filter level such that all
    // conditions of the prop-filter need to be satisfied by the same prop-filter value
    public const FEAT_ALLOF_SINGLEPROP = 2 ** 5;

    // Feature is set if the server supports result limiting requested by the client. Affects addressbook query report.
    public const FEAT_RESULTLIMIT = 2 ** 6;

    // Feature is set if the server supports partial retrieval of addressdata for addressbook-query report.
    public const FEAT_ABOOKQUERY_PARTIALCARDS = 2 ** 7;

    // Server bug: sync-collection report with empty sync-token is rejected with 400 bad request
    public const BUG_REJ_EMPTY_SYNCTOKEN = 2 ** 10;

    // Server bug in sabre/dav: if a param-filter match is done on a VCard that has the asked for property without the
    // parameter, a null value will be dereferenced, resulting in an internal server error
    public const BUG_PARAMFILTER_ON_NONEXISTENT_PARAM = 2 ** 11;

    // Server bug in Google + Davical: A prop-filter with a negated text-match filter will match VCards where the
    // property in question does not exist
    public const BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS = 2 ** 12;

    // Server bug in Google + Sabre/DAV: A prop-filter with a negated text-match filter will not match if there is
    // another instance of the property in question that matches the non-negated filter
    public const BUG_INVTEXTMATCH_SOMEMATCH = 2 ** 13;

    // Server bug in Google: A prop-filter with a param-filter subfilter that matches on a not-defined parameter will
    // match vCards where the property does not exist.
    public const BUG_PARAMNOTDEF_MATCHES_UNDEF_PROPS = 2 ** 14;

    // Server bug in Davical: A prop-filter with a param-filter/is-not-defined filter will match if there is at least
    // one property of the asked for type that lacks the parameter, but it must only match if the parameter occurs with
    // no property of the asked for type
    public const BUG_PARAMNOTDEF_SOMEMATCH = 2 ** 15;

    // Server bug in Davical: A text-match for a param-filter is performed on the property value, not the parameter
    // value. Furthermore collation and match-type are ignored, not that it really matters considering the wrong value
    // is compared :-)
    public const BUG_PARAMTEXTMATCH_BROKEN = 2 ** 16;

    // Server bug in Google: A negated text-match on a parameter matches if the parameter is not defined. It also
    // matches if the property is not defined.
    public const BUG_INVTEXTMATCH_MATCHES_UNDEF_PARAMS = 2 ** 17;

    // Server bug in Radicale: Multiple conditions in a prop-filter are always evaluated as if test=allof was given.
    public const BUG_PROPFILTER_ALLOF = 2 ** 18;

    // Server bug: param-filter on multi-value parameter is matched against a string of all parameter values, not
    // against the individual values. Example: param-filter with equals text-match on HOME woud not match TYPE=HOME,WORK
    public const BUG_MULTIPARAM_NOINDIVIDUAL_MATCH = 2 ** 19;

    // Server bug: In addressbook-query, the server treates property names and group names case sensitive.
    public const BUG_CASESENSITIVE_NAMES = 2 ** 20;

    // Server bug: Group prefixes in prop-filter are not properly handled
    public const BUG_HANDLE_PROPGROUPS_IN_QUERY = 2 ** 21;

    // Server bug: param-filter without subfilter matches also properties that do not have the parameter
    public const BUG_PARAMDEF = 2 ** 22;

    // Server bug: parameter values with a quoted comma are considered as multiple values by the server
    public const BUG_PARAMCOMMAVALUE = 2 ** 23;

    public const SRVFEATS_ICLOUD = self::FEAT_SYNCCOLL | self::FEAT_MULTIGET | self::FEAT_CTAG
        | self::FEAT_ALLOF_SINGLEPROP | self::BUG_CASESENSITIVE_NAMES;

    public const SRVFEATS_GOOGLE = self::FEAT_SYNCCOLL | self::FEAT_MULTIGET | self::FEAT_CTAG
        | self::FEAT_PARAMFILTER | self::FEAT_FILTER_ALLOF | self::FEAT_RESULTLIMIT | self::FEAT_ABOOKQUERY_PARTIALCARDS
        | self::BUG_REJ_EMPTY_SYNCTOKEN
        | self::BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS
        | self::BUG_INVTEXTMATCH_SOMEMATCH
        | self::BUG_PARAMNOTDEF_MATCHES_UNDEF_PROPS
        | self::BUG_INVTEXTMATCH_MATCHES_UNDEF_PARAMS
        | self::BUG_MULTIPARAM_NOINDIVIDUAL_MATCH
        | self::BUG_CASESENSITIVE_NAMES;

    public const SRVFEATSONLY_SABRE = self::FEAT_SYNCCOLL | self::FEAT_MULTIGET | self::FEAT_CTAG
        | self::FEAT_PARAMFILTER | self::FEAT_FILTER_ALLOF | self::FEAT_RESULTLIMIT
        | self::FEAT_ABOOKQUERY_PARTIALCARDS
        | self::BUG_MULTIPARAM_NOINDIVIDUAL_MATCH;
    public const SRVBUGS_SABRE = self::BUG_PARAMFILTER_ON_NONEXISTENT_PARAM
        | self::BUG_INVTEXTMATCH_SOMEMATCH;
    public const SRVFEATS_SABRE = self::SRVFEATSONLY_SABRE | self::SRVBUGS_SABRE;
    public const SRVFEATS_RADICALE = self::FEAT_SYNCCOLL | self::FEAT_MULTIGET | self::FEAT_CTAG
        | self::FEAT_PARAMFILTER | self::FEAT_FILTER_ALLOF
        | self::BUG_INVTEXTMATCH_SOMEMATCH | self::BUG_PROPFILTER_ALLOF | self::BUG_HANDLE_PROPGROUPS_IN_QUERY;
    public const SRVFEATS_DAVICAL = self::FEAT_SYNCCOLL | self::FEAT_MULTIGET | self::FEAT_CTAG
        | self::FEAT_PARAMFILTER | self::FEAT_FILTER_ALLOF | self::FEAT_ALLOF_SINGLEPROP | self::FEAT_RESULTLIMIT
        | self::FEAT_ABOOKQUERY_PARTIALCARDS
        | self::BUG_PARAMCOMMAVALUE
        //| self::BUG_MULTIPARAM_NOINDIVIDUAL_MATCH
        //| self::BUG_CASESENSITIVE_NAMES
        //| self::BUG_HANDLE_PROPGROUPS_IN_QUERY
        // fixed locally | self::BUG_INVTEXTMATCH_MATCHES_UNDEF_PROPS
        // fixed locally | self::BUG_PARAMNOTDEF_SOMEMATCH
        // fixed locally | self::BUG_PARAMTEXTMATCH_BROKEN
        // fixed locally | self::BUG_PARAMDEF
        ;
    public const SRVFEATS_SYNOLOGY_CONTACTS = self::SRVFEATS_RADICALE; // uses Radicale

    /** @var array<string, Account> Objects for all accounts from AccountData::ACCOUNTS */
    public static $accounts = [];

    /** @var array<string, AddressbookCollection> Objects for all addressbooks from AccountData::ADDRESSBOOKS */
    public static $addressbooks = [];

    public static function init(): void
    {
        if (empty(self::$accounts)) {
            $logfileHttp = 'testreports/interop/tests_http.log';
            if (file_exists($logfileHttp)) {
                unlink($logfileHttp);
            }

            TestInfrastructure::init(new FileLogger($logfileHttp, \Psr\Log\LogLevel::DEBUG));
        }

        foreach (AccountData::ACCOUNTS as $name => $cfg) {
            self::$accounts[$name] = new Account($cfg["discoveryUri"], $cfg["username"], $cfg["password"]);
        }

        foreach (AccountData::ADDRESSBOOKS as $name => $cfg) {
            self::$addressbooks[$name] = new AddressbookCollection($cfg["url"], self::$accounts[$cfg["account"]]);
        }
    }

    /**
     * @return array<string, array{string, TestAccount}>
     */
    public static function accountProvider(): array
    {
        $ret = [];
        foreach (AccountData::ACCOUNTS as $name => $cfg) {
            $ret[$name] = [ $name, $cfg ];
        }
        return $ret;
    }

    /**
     * Returns all addressbooks.
     *
     * If $excludeReadOnly is true, addressbooks marked as readonly will be excluded from the result set. This can be
     * used to skip readonly addressbooks in tests that require writing to the addressbook. It can also be used to skip
     * tests on multiple addressbooks of the same server, which would only increase the time needed to execute the
     * tests.
     *
     * @return array<string, array{string, TestAddressbook}>
     */
    public static function addressbookProvider(bool $excludeReadOnly = true): array
    {
        $ret = [];
        foreach (AccountData::ADDRESSBOOKS as $name => $cfg) {
            if ($excludeReadOnly && ($cfg["readonly"] ?? false)) {
                continue;
            }
            $ret[$name] = [ $name, $cfg ];
        }
        return $ret;
    }

    /**
     * Checks if the given addressbook has the feature $reqFeature.
     *
     * If multiple bits are set in $reqFeature, if $any is true, it is sufficient if any of the features / bugs is
     * present. If $any is false, all features/bugs must be present.
     */
    public static function hasFeature(string $abookname, int $reqFeature, bool $any = true): bool
    {
        TestCase::assertArrayHasKey($abookname, AccountData::ADDRESSBOOKS);
        $abookcfg = AccountData::ADDRESSBOOKS[$abookname];

        $accountname = $abookcfg["account"];
        TestCase::assertArrayHasKey($accountname, AccountData::ACCOUNTS);
        $accountcfg = AccountData::ACCOUNTS[$accountname];

        $featureSet = $accountcfg["featureSet"];
        if ($any) {
            return (($featureSet & $reqFeature) != 0);
        } else {
            return (($featureSet & $reqFeature) == $reqFeature);
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
