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
use donatj\MockWebServer\{MockWebServer,Response};
use MStilkerich\CardDavClient\{Account,WebDavResource};
use MStilkerich\Tests\CardDavClient\TestInfrastructure;

/**
 * @psalm-import-type HttpOptions from Account
 */
final class HttpClientAdapterGuzzleTest extends TestCase
{
    /** @var MockWebServer */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new MockWebServer();
        self::$server->start();
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
        // stopping the web server during tear down allows us to reuse the port for later tests
        self::$server->stop();
    }

    public function testQueryStringIsAppendedToRequests(): void
    {
        $query = ['a' => 'hello', 'b' => 'world'];
        $json = $this->sendRequest(['query' => $query]);

        foreach ($query as $opt => $val) {
            $this->assertSame($val, $json['_GET'][$opt]);
        }
    }

    public function testHeadersAreAddedToRequest(): void
    {
        $hdr = ['User-Agent' => 'carddavclient/dev', 'X-FOO' => 'bar'];
        $json = $this->sendRequest(['headers' => $hdr]);

        foreach ($hdr as $h => $val) {
            $this->assertSame($val, $json['HEADERS'][$h]);
        }
    }

    public function testAuthHeaderSentPreemptively(): void
    {
        // First test that normally the Authorization is not sent
        $json = $this->sendRequest();
        $this->assertArrayNotHasKey('Authorization', $json['HEADERS']);

        // Second do the same request with preemptive_basic_auth
        $json = $this->sendRequest(['preemptive_basic_auth' => true]);
        $hdrValue = base64_encode('user:pw');
        $this->assertSame("Basic $hdrValue", $json['HEADERS']['Authorization']);

        // Third make sure that the internal Authorization header overrides a user-defined header if given
        $json = $this->sendRequest(['preemptive_basic_auth' => true, 'headers' => ['Authorization' => 'Foo']]);
        $this->assertSame("Basic $hdrValue", $json['HEADERS']['Authorization']);

        // Fourth check that a user-defined Authorization could be given if carddavclient does not create one
        $json = $this->sendRequest(['headers' => ['Authorization' => 'Foo']]);
        $this->assertSame("Foo", $json['HEADERS']['Authorization']);
    }

    /**
     * When the internal lib also produces headers, they should take precedence over the user-defined headers for the
     * account. To test this we need to use a request where the internal lib generates request-specific headers, we'll
     * do a PROPFIND. We also need to have the mock server return a valid result so the client does not err out.
     */
    public function testHeadersCombinedProperly(): void
    {
        $xml = <<<'END'
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" 
    xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/"
    xmlns:card="urn:ietf:params:xml:ns:carddav">

  <d:response>
    <d:href>/coll/</d:href>
    <d:propstat>
      <d:prop>
        <d:resourcetype><d:collection/><card:addressbook/></d:resourcetype>
        <d:sync-token>http://sabre.io/ns/sync/1</d:sync-token>
        <d:supported-report-set>
          <d:supported-report><d:report><d:expand-property/></d:report></d:supported-report>
          <d:supported-report><d:report><d:principal-match/></d:report></d:supported-report>
          <d:supported-report><d:report><d:principal-property-search/></d:report></d:supported-report>
          <d:supported-report><d:report><d:principal-search-property-set/></d:report></d:supported-report>
          <d:supported-report><d:report><d:sync-collection/></d:report></d:supported-report>
          <d:supported-report><d:report><card:addressbook-multiget/></d:report></d:supported-report>
          <d:supported-report><d:report><card:addressbook-query/></d:report></d:supported-report>
        </d:supported-report-set>
        <d:displayname>Default Address Book</d:displayname>
        <cs:getctag>1</cs:getctag>
        <card:supported-address-data>
          <card:address-data-type content-type="text/vcard" version="3.0"/>
          <card:address-data-type content-type="text/vcard" version="4.0"/>
          <card:address-data-type content-type="application/vcard+json" version="4.0"/>
        </card:supported-address-data>
        <card:addressbook-description>Default Address Book</card:addressbook-description>
        <card:max-resource-size>10000000</card:max-resource-size>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>
END;

        $url = self::$server->setResponseOfPath(
            '/coll/',
            new Response(
                $xml,
                [ 'Content-Type' => 'application/xml; charset=utf-8' ],
                207
            )
        );

        $httpOptions = [
            'username' => 'user',
            'password' => 'pw',
            'headers' => [
                'Depth' => '5',
                'Foo' => '5',
            ],
        ];

        $baseUri = self::$server->getServerRoot();
        $account = new Account($baseUri, $httpOptions, "", $baseUri);

        $res = new WebDavResource($url, $account);
        $res->refreshProperties();

        $propfindReq = self::$server->getLastRequest();
        $this->assertNotNull($propfindReq);

        $reqHeaders = $propfindReq->getHeaders();
        $this->assertSame('0', $reqHeaders['Depth']);
        $this->assertSame('5', $reqHeaders['Foo']);
    }

    /**
     * @param HttpOptions $options
     * @return array{HEADERS: array, _GET: array, ...}
     */
    private function sendRequest(array $options = []): array
    {
        $httpOptions = $options + [
            'username' => 'user',
            'password' => 'pw',
        ];
        $baseUri = self::$server->getServerRoot();

        $account = new Account($baseUri, $httpOptions, "", $baseUri);
        $res = new WebDavResource($baseUri, $account);
        $resp = $res->downloadResource('/res');
        $json = json_decode($resp['body'], true);
        /**
          '_GET' => array ( 'a' => 'hello', 'b' => 'world',),
          '_POST' => array (),
          '_FILES' => array (),
          '_COOKIE' => array (),
          'HEADERS' => array ( 'Host' => '127.0.0.1:36143', 'User-Agent' => 'GuzzleHttp/7',),
          'METHOD' => 'GET',
          'INPUT' => '',
          'PARSED_INPUT' => array (),
          'REQUEST_URI' => '/res?a=hello&b=world',
          'PARSED_REQUEST_URI' => array ( 'path' => '/res', 'query' => 'a=hello&b=world',),
         */
        $this->assertIsArray($json);
        $this->assertIsArray($json['HEADERS']);
        $this->assertIsArray($json['_GET']);
        return $json;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
