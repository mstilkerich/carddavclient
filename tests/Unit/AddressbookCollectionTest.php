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
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\Tests\CardDavClient\TestInfrastructure;
use Sabre\VObject\Component\VCard;

final class AddressbookCollectionTest extends TestCase
{
    /** @var MockWebServer */
    private static $server;

    /** @var AddressbookCollection */
    private $abook;

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

    protected function setUp(): void
    {
        $xml = <<<'END'
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/coll/</d:href>
    <d:propstat>
      <d:prop>
        <d:resourcetype><d:collection/><card:addressbook/></d:resourcetype>
        <d:displayname>Address Book</d:displayname>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>
END;
        $url = self::$server->setResponseOfPath(
            '/coll/',
            new Response($xml, [ 'Content-Type' => 'application/xml; charset=utf-8' ], 207)
        );
        self::$server->setDefaultResponse(new Response('', [ 'ETag' => '"new"' ], 201));

        $baseUri = self::$server->getServerRoot();
        $account = new Account($baseUri, [ 'username' => 'user', 'password' => 'pw' ], "", $baseUri);
        $this->abook = new AddressbookCollection($url, $account);
    }

    public function testInvalidCardIsRefusedByDefault(): void
    {
        $vcard = $this->invalidCard();

        try {
            $this->abook->updateCard('/coll/card.vcf', $vcard, '"old"');
            $this->fail("Expected InvalidArgumentException not thrown");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("not allowed to accept vCard 2.1", $e->getMessage());
        }

        TestInfrastructure::logger()->expectMessage('error', 'Issue with provided VCard');
    }

    public function testUpdateWithoutValidationSendsCardAsIs(): void
    {
        $etag = $this->abook->updateCard('/coll/card.vcf', $this->invalidCard(), '"old"', false);

        $this->assertSame('"new"', $etag);
        $request = self::$server->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('PUT', $request->getRequestMethod());
        $this->assertStringContainsString("VERSION:2.1", $request->getInput());
    }

    public function testCreateWithoutValidationSendsCardAsIs(): void
    {
        $vcard = $this->invalidCard();
        $vcard->UID = 'abc';

        $result = $this->abook->createCard($vcard, false);

        $this->assertSame('"new"', $result['etag']);
        $request = self::$server->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('PUT', $request->getRequestMethod());
        $this->assertStringContainsString("VERSION:2.1", $request->getInput());
    }

    private function invalidCard(): VCard
    {
        $vcard = TestInfrastructure::createVCard();
        $vcard->VERSION = '2.1'; // not allowed for CardDAV

        return $vcard;
    }
}
