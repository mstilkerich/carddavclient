<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Interop;

use MStilkerich\Tests\CardDavClient\{TestInfrastructure,TestLogger};
use MStilkerich\CardDavClient\{Account,AddressbookCollection,Config,HttpClientAdapter};
use PHPUnit\Framework\TestCase;

/**
 * @psalm-import-type HttpOptions from Account
 *
 * @psalm-type TestAccount = array{
 *   username?: string,
 *   password?: string,
 *   refreshtoken?: string,
 *   tokenUri?: string,
 *   clientId?: string,
 *   clientSecret?: string,
 *   oAuthScopes?: string,
 *   discoveryUri: string,
 *   syncAllowExtraChanges: bool,
 *   featureSet: int,
 *   httpOptions?: HttpOptions,
 * }
 *
 * @psalm-type TestAddressbook = array{
 *   account: string,
 *   url: string,
 *   displayname: ?string,
 *   description: ?string,
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

    // Server bug: If-match ETag precondition check is not performed, i.e. requests succeeds even in case of mismatch
    public const BUG_ETAGPRECOND_NOTCHECKED = 2 ** 24;

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
        | self::BUG_CASESENSITIVE_NAMES
        | self::BUG_ETAGPRECOND_NOTCHECKED;

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

    /** @var bool */
    private static $initialized = false;

    /** @var array<string, Account> Objects for all accounts from AccountData::ACCOUNTS */
    private static $accounts = [];

    /** @var array<string, AddressbookCollection> Objects for all addressbooks from AccountData::ADDRESSBOOKS */
    private static $addressbooks = [];

    private static function replaceEnvVar(string $setting): string
    {
        $matches = [];
        if (preg_match('/^%([A-Za-z0-9_]+)%$/', $setting, $matches)) {
            $setting = getenv($matches[1]);
            if (!is_string($setting)) {
                return "";
            }
        }
        return $setting;
    }

    /**
     * @psalm-param TestAccount $cfg
     * @psalm-return HttpOptions
     */
    public static function makeCredentials(array $cfg): array
    {
        $cred = [];
        if (isset($cfg['username'])) {
            $cred['username'] = self::replaceEnvVar($cfg['username']);
        }
        if (isset($cfg['password'])) {
            $cred['password'] = self::replaceEnvVar($cfg['password']);
        }

        if (isset($cfg['httpOptions'])) {
            $cred = $cred + $cfg['httpOptions'];
        }

        if (
            isset($cfg['tokenUri'])
            && isset($cfg['refreshtoken'])
            && isset($cfg['clientId'])
            && isset($cfg['clientSecret'])
        ) {
            // Fetch an access token using the available refresh token
            $client = new \GuzzleHttp\Client();
            $postData = [
                'grant_type' => 'refresh_token',
                'refresh_token' => self::replaceEnvVar($cfg['refreshtoken']),
                'client_id' => self::replaceEnvVar($cfg['clientId']),
                'client_secret' => self::replaceEnvVar($cfg['clientSecret']),
            ];

            if (isset($cfg['oAuthScopes'])) {
                $postData['scope'] = $cfg['oAuthScopes'];
            }

            $response = $client->request('POST', $cfg['tokenUri'], ['form_params' => $postData]);
            $body = (string) $response->getBody();

            /** @var array | false */
            $json = json_decode($body, true);
            if (isset($json["access_token"]) && is_string($json["access_token"])) {
                $cred["bearertoken"] = $json["access_token"];
            }
        }

        return $cred;
    }

    public static function init(): void
    {
        if (!self::$initialized) {
            self::$initialized = true;
            TestInfrastructure::init(new TestLogger('tests_http.log'));
        }
    }

    public static function getAccount(string $name): Account
    {
        $accountCfgs = self::accountProvider();

        if (!isset(self::$accounts[$name])) {
            TestCase::assertArrayHasKey($name, $accountCfgs, "Unknown account $name");
            $cfg = $accountCfgs[$name][1];
            $cred = self::makeCredentials($cfg);
            self::$accounts[$name] = new Account($cfg["discoveryUri"], $cred);
        }

        return self::$accounts[$name];
    }

    public static function getAddressbook(string $name): AddressbookCollection
    {
        if (!isset(self::$addressbooks[$name])) {
            TestCase::assertArrayHasKey($name, AccountData::ADDRESSBOOKS, "Unknown addressbook $name");
            $cfg = AccountData::ADDRESSBOOKS[$name];
            $account = self::getAccount($cfg['account']);

            self::$addressbooks[$name] = new AddressbookCollection(
                self::replaceEnvVar($cfg["url"]),
                $account
            );
        }

        return self::$addressbooks[$name];
    }

    /**
     * @return array<string, array{string, TestAccount}>
     */
    public static function accountProvider(): array
    {
        $ret = [];
        $accountFilter = getenv('CARDDAVCLIENT_INTEROP_SRV');

        foreach (AccountData::ACCOUNTS as $name => $cfg) {
            if (is_string($accountFilter) && ($accountFilter != $name)) {
                continue;
            }

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
        $accounts = self::accountProvider();

        foreach (AccountData::ADDRESSBOOKS as $name => $cfg) {
            /**
             * @psalm-var 0|bool $readonly
             *   Actually this can only be true or false - this is to make psalm quiet depending on some AccountData
             *   configs where only a fixed value for readonly is used.
             */
            $readonly = $cfg["readonly"] ?? false;
            $cfg["url"] = self::replaceEnvVar($cfg["url"]);

            if ($excludeReadOnly && $readonly) {
                continue;
            }

            if (isset($accounts[$cfg["account"]])) {
                $ret[$name] = [ $name, $cfg ];
            }
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
