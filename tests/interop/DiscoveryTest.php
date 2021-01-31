<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Interop;

use MStilkerich\Tests\CardDavClient\TestInfrastructure;
use MStilkerich\CardDavClient\Account;
use MStilkerich\CardDavClient\Services\Discovery;
use PHPUnit\Framework\TestCase;

/**
 * @psalm-import-type TestAccount from TestInfrastructureSrv
 */
final class DiscoveryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestInfrastructureSrv::init();
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
    }


    /**
     * @return array<string, array{string, TestAccount}>
     */
    public function accountProvider(): array
    {
        return TestInfrastructureSrv::accountProvider();
    }

    /**
     * @param TestAccount $cfg
     * @dataProvider accountProvider
     */
    public function testAllAddressbooksCanBeDiscovered(string $accountname, array $cfg): void
    {
        $account = TestInfrastructureSrv::$accounts[$accountname];
        $this->assertInstanceOf(Account::class, $account);

        $abookUris = [];
        foreach (TestInfrastructureSrv::$addressbooks as $abook) {
            if ($abook->getAccount() === $account) {
                $abookUris[] = $abook->getUri();
            }
        }

        $discover = new Discovery();
        $abooks = $discover->discoverAddressbooks($account);

        $this->assertCount(count($abookUris), $abooks, "Unexpected number of addressbooks discovered");

        foreach ($abooks as $abook) {
            $this->assertContains($abook->getUri(), $abookUris, "Unexpected addressbook discovered");
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
