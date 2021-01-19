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
 *   syncAllowExtraChanges: bool
 * }
 *
 * @psalm-type TestAddressbook = array{
 *   account: string,
 *   url: string,
 *   displayname: string,
 *   supports_synccoll: bool,
 *   supports_multiget: bool,
 *   supports_ctag: bool,
 *   readonly?: bool
 * }
 */

final class TestInfrastructureSrv
{
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
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
