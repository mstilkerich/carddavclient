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
use MStilkerich\CardDavClient\Exception\HttpStatusException;
use MStilkerich\Tests\CardDavClient\TestInfrastructure;

final class CardDavClientErrorsTest extends TestCase
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

    /**
     * @return array<string, array{int}>
     */
    public static function statusProvider(): array
    {
        return [
            'unauthorized' => [401],
            'forbidden' => [403],
            'not found' => [404],
            'server error' => [500],
        ];
    }

    /**
     * @dataProvider statusProvider
     */
    public function testUnexpectedStatusThrowsExceptionWithStatusCode(int $status): void
    {
        self::$server->setDefaultResponse(new Response('Nope', [], $status));
        $client = new CardDavClient(self::$server->getServerRoot(), ['username' => 'user', 'password' => 'pw']);

        try {
            $client->getResource('/card.vcf');
            $this->fail("No exception thrown for status $status");
        } catch (HttpStatusException $e) {
            $this->assertSame($status, $e->getStatusCode());
            $this->assertSame($status, $e->getCode());
            $this->assertSame('Nope', $e->getBody());
            $this->assertMatchesRegularExpression(
                "/GET \\/card.vcf HTTP request was not successful \\($status .*\\)$/",
                $e->getMessage()
            );
            $this->assertStringNotContainsString('Nope', $e->getMessage());
        }
    }
}
