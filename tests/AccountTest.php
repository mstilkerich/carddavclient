<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient;

use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavClient\{Account,AddressbookCollection};

final class AccountTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
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
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
