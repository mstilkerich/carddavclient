<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Unit;

use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\Tests\CardDavClient\TestInfrastructure;

final class AccountTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestInfrastructure::init();
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

    public function testCanBeCreatedFromValidData(): void
    {
        $account = new Account("example.com", "theUser", "thePassword");

        $this->expectException(\Exception::class);
        $account->getUrl();
    }

    public function testAccountToStringContainsEssentialData(): void
    {
        $account = new Account("example.com", "theUser", "thePassword", "https://carddav.example.com:443");
        $s = (string) $account;

        $this->assertStringContainsString("example.com", $s);
        $this->assertStringContainsString("user: theUser", $s);
        $this->assertStringContainsString("CardDAV URI: https://carddav.example.com:443", $s);
    }

    public function testCanBeCreatedFromArray(): void
    {
        $account = new Account("example.com", "theUser", "thePassword", "https://carddav.example.com:443");

        $accSerial = [
            'username' => 'theUser',
            'password' => 'thePassword',
            'baseUrl' => 'https://carddav.example.com:443',
            'discoveryUri' => 'example.com'
        ];
        $accountExp = Account::constructFromArray($accSerial);

        $this->assertEquals($accountExp, $account);
    }

    public function testCanBeCreatedFromArrayWithoutOptionalProps(): void
    {
        $account = new Account("example.com", "theUser", "thePassword");

        $accSerial = [
            'username' => 'theUser',
            'password' => 'thePassword',
            'discoveryUri' => 'example.com'
        ];
        $accountExp = Account::constructFromArray($accSerial);

        $this->assertEquals($accountExp, $account);
    }

    public function testCanBeCreatedFromArrayWithoutRequiredProps(): void
    {
        $accSerial = [
            'username' => 'theUser',
            'password' => 'thePassword',
            'baseUrl' => 'https://carddav.example.com:443',
        ];
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("does not contain required property discoveryUri");
        /** @psalm-suppress InvalidArgument intended invalid argument passed for test */
        Account::constructFromArray($accSerial);
    }

    public function testCanBeSerializedToJson(): void
    {
        $account = new Account("example.com", "theUser", "thePassword", "https://carddav.example.com:443");

        $accSerial = $account->jsonSerialize();
        $accSerialExp = [
            'username' => 'theUser',
            'password' => 'thePassword',
            'baseUrl' => 'https://carddav.example.com:443',
            'discoveryUri' => 'example.com'
        ];

        $this->assertEquals($accSerialExp, $accSerial);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
