<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2026 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Unit;

use PHPUnit\Framework\TestCase;
use donatj\MockWebServer\{MockWebServer,Response};
use MStilkerich\CardDavClient\CardDavClient;
use MStilkerich\Tests\CardDavClient\TestInfrastructure;

final class CardDavClientTest extends TestCase
{
    /** @var MockWebServer */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new MockWebServer();
        self::$server->start();
        TestInfrastructure::init();
    }

    public static function tearDownAfterClass(): void
    {
        // stopping the web server during tear down allows us to reuse the port for later tests
        self::$server->stop();
    }

    public function testDeleteWithoutETagIsUnconditional(): void
    {
        self::$server->setDefaultResponse(new Response('', [], 204));

        $this->assertTrue($this->client()->deleteResource('/card.vcf'));

        $request = self::$server->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('DELETE', $request->getRequestMethod());
        $this->assertArrayNotHasKey('If-Match', $request->getHeaders());
    }

    public function testDeleteWithETagIsConditional(): void
    {
        self::$server->setDefaultResponse(new Response('', [], 204));

        $this->assertTrue($this->client()->deleteResource('/card.vcf', '"abc"'));

        $request = self::$server->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('"abc"', $request->getHeaders()['If-Match']);
    }

    public function testDeleteReturnsFalseIfETagDoesNotMatch(): void
    {
        self::$server->setDefaultResponse(new Response('', [], 412));

        $this->assertFalse($this->client()->deleteResource('/card.vcf', '"outdated"'));
    }

    public function testDeleteFailsOnOtherErrors(): void
    {
        self::$server->setDefaultResponse(new Response('', [], 500));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/DELETE .*HTTP.*500/');
        $this->client()->deleteResource('/card.vcf', '"abc"');
    }

    private function client(): CardDavClient
    {
        return new CardDavClient(self::$server->getServerRoot(), ['username' => 'user', 'password' => 'pw']);
    }
}
