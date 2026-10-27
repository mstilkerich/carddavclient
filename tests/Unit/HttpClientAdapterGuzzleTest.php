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
use PHPUnit\Framework\Attributes\DataProvider;
use donatj\MockWebServer\{MockWebServer,Response};
use MStilkerich\CardDavClient\{Account,HttpClientAdapter,HttpClientAdapterGuzzle,WebDavResource};
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
     * @return array<string, array{string, string, bool}>
     */
    public static function originProvider(): array
    {
        return [
            'identical' => [ 'https://example.com/a/', 'https://example.com/b', true ],
            'case-insensitive' => [ 'HTTPS://Example.COM/', 'https://example.com/', true ],
            'default port https' => [ 'https://example.com/', 'https://example.com:443/x', true ],
            'default port http' => [ 'http://example.com:80/', 'http://example.com/x', true ],
            'different scheme' => [ 'https://example.com/', 'http://example.com/', false ],
            'different port' => [ 'https://example.com/', 'https://example.com:8443/', false ],
            'subdomain' => [ 'https://example.com/', 'https://dav.example.com/', false ],
            'same last two labels' => [ 'https://a.co.uk/', 'https://evil.co.uk/', false ],
            'IPv4 same' => [ 'https://192.168.1.10/', 'https://192.168.1.10:443/x', true ],
            'IPv4 same last octets' => [ 'https://192.168.1.10/', 'https://10.20.1.10/', false ],
            'IPv4 vs name' => [ 'https://192.168.1.10/', 'https://evil.1.10/', false ],
            'IPv4 vs hex name' => [ 'https://192.168.1.10/', 'https://c0a8010a/', false ],
            'IPv6 notations' => [ 'https://[2001:db8::1]/', 'https://[2001:DB8:0:0::1]/', true ],
            'IPv6 different' => [ 'https://[2001:db8::1]/', 'https://[2001:db8::2]/', false ],
            'IPv4-mapped IPv6' => [ 'https://[::ffff:192.168.1.10]/', 'https://192.168.1.10/', true ],
            'IPv4-mapped IPv6 different' => [ 'https://[::ffff:192.168.1.10]/', 'https://[::ffff:10.0.1.10]/', false ],
            'relative' => [ '/a/', '/a/', false ],
        ];
    }

    #[DataProvider('originProvider')]
    public function testOriginComparison(string $uri1, string $uri2, bool $expSame): void
    {
        $this->assertSame($expSame, HttpClientAdapter::isSameOrigin($uri1, $uri2));
        $this->assertSame($expSame, HttpClientAdapter::isSameOrigin($uri2, $uri1));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function originFormatProvider(): array
    {
        return [
            'name, default port' => [ 'HTTPS://Example.COM/x?y#z', 'https://example.com:443' ],
            'name, explicit port' => [ 'http://example.com:8080/', 'http://example.com:8080' ],
            'IPv4' => [ 'http://192.168.1.10/', 'http://192.168.1.10:80' ],
            'IPv6' => [ 'https://[2001:DB8:0:0::1]:8443/', 'https://[2001:db8::1]:8443' ],
            'IPv4-mapped IPv6' => [ 'https://[::ffff:192.168.1.10]/', 'https://192.168.1.10:443' ],
            'unknown scheme without port' => [ 'ftp://example.com/x', 'ftp://example.com' ],
            'unknown scheme with port' => [ 'ftp://example.com:2121/x', 'ftp://example.com:2121' ],
            'relative' => [ '/x', null ],
        ];
    }

    #[DataProvider('originFormatProvider')]
    public function testOriginFormat(string $uri, ?string $expOrigin): void
    {
        $getOrigin = new \ReflectionMethod(HttpClientAdapter::class, 'getOrigin');
        $this->assertSame($expOrigin, $getOrigin->invoke(null, $uri));
    }

    public function testCredentialsNotSentToDifferentOrigin(): void
    {
        $json = $this->sendRequest(['preemptive_basic_auth' => true], $this->otherOriginUri('/res'));
        $this->assertArrayNotHasKey('Authorization', $json['HEADERS']);
    }

    public function testCallbackPermitsCredentialsForDifferentOrigin(): void
    {
        /** @var \ArrayObject<int, array{string, string}> $calls */
        $calls = new \ArrayObject();
        $callback = function (string $targetUri, string $baseUri) use ($calls): bool {
            $calls->append([ $targetUri, $baseUri ]);
            return true;
        };

        // same origin: callback is not consulted
        $json = $this->sendRequest(['preemptive_basic_auth' => true, 'auth_origin_callback' => $callback]);
        $this->assertArrayHasKey('Authorization', $json['HEADERS']);
        $this->assertCount(0, $calls);

        // different origin: callback is consulted and permits sending the credentials
        $target = $this->otherOriginUri('/res');
        $json = $this->sendRequest(['preemptive_basic_auth' => true, 'auth_origin_callback' => $callback], $target);
        $this->assertSame('Basic ' . base64_encode('user:pw'), $json['HEADERS']['Authorization']);
        $this->assertSame([ [ $target, self::$server->getServerRoot() ] ], $calls->getArrayCopy());
    }

    public function testCallbackCanRejectCredentialsForDifferentOrigin(): void
    {
        $callback = function (): bool {
            return false;
        };

        $json = $this->sendRequest(
            ['preemptive_basic_auth' => true, 'auth_origin_callback' => $callback],
            $this->otherOriginUri('/res')
        );
        $this->assertArrayNotHasKey('Authorization', $json['HEADERS']);
    }

    public function testCredentialsNeverSentFromHttpsToHttp(): void
    {
        $called = false;
        $callback = function () use (&$called): bool {
            $called = true;
            return true;
        };

        // the base URI uses HTTPS, the request (to the same host and port) plain HTTP
        $root = self::$server->getServerRoot();
        $httpsBase = preg_replace('/^http:/', 'https:', $root);
        $this->assertIsString($httpsBase);

        $json = $this->sendRequest(
            ['preemptive_basic_auth' => true, 'auth_origin_callback' => $callback],
            "$root/res",
            $httpsBase
        );
        $this->assertArrayNotHasKey('Authorization', $json['HEADERS']);
        $this->assertFalse($called);
    }

    /**
     * A callback set using Account::setAuthOriginCallback() applies to resources created before, and can be removed.
     */
    public function testCallbackSetOnAccountAppliesToExistingResources(): void
    {
        $baseUri = self::$server->getServerRoot();
        $account = new Account($baseUri, ['username' => 'user', 'password' => 'pw', 'preemptive_basic_auth' => true]);
        $res = new WebDavResource($baseUri, $account);
        $target = $this->otherOriginUri('/res');

        $account->setAuthOriginCallback(function (): bool {
            return true;
        });
        $this->assertArrayHasKey('Authorization', $this->downloadRequestHeaders($res, $target));

        $account->setAuthOriginCallback(null);
        $this->assertNull($account->getAuthOriginCallback());
        $this->assertArrayNotHasKey('Authorization', $this->downloadRequestHeaders($res, $target));
    }

    /**
     * Downloads a URI from the mock server using the given resource and returns the headers of the request.
     *
     * @return array<string, mixed> The headers of the request as received by the mock server
     */
    private function downloadRequestHeaders(WebDavResource $res, string $uri): array
    {
        /** @psalm-var mixed $json */
        $json = json_decode($res->downloadResource($uri)['body'], true);
        $this->assertIsArray($json);
        $this->assertIsArray($json['HEADERS']);
        /** @psalm-var array<string, mixed> */
        return $json['HEADERS'];
    }

    public function testCallbackIsNotSerialized(): void
    {
        $account = new Account('example.com', [
            'username' => 'user',
            'auth_origin_callback' => function (): bool {
                return true;
            }
        ]);
        $this->assertSame(
            [ 'discoveryUri' => 'example.com', 'baseUrl' => null, 'username' => 'user' ],
            $account->jsonSerialize()
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function authSchemeProvider(): array
    {
        $schemes = [ 'basic', 'bearer' ];
        if (extension_loaded('curl')) {
            $schemes = array_merge($schemes, [ 'digest', 'ntlm', 'curlany' ]);
            if (defined('CURLAUTH_NEGOTIATE')) {
                $schemes[] = 'negotiate';
            }
        }

        $data = [];
        foreach ($schemes as $scheme) {
            $data[$scheme] = [ $scheme ];
        }
        return $data;
    }

    /**
     * Redirects that are followed by Guzzle must not carry the credentials to a different origin, regardless of the
     * authentication scheme in use, even if the redirect target asks for authentication.
     */
    #[DataProvider('authSchemeProvider')]
    public function testCredentialsNotSentOnRedirectToDifferentOrigin(string $authScheme): void
    {
        $target = $this->otherOriginUri('/challenge');
        $redirect = self::$server->setResponseOfPath('/redirect', new Response('', [ 'Location' => $target ], 302));
        self::$server->setResponseOfPath('/challenge', new Response(
            'Unauthorized',
            [ 'WWW-Authenticate' => 'Digest realm="test", nonce="abc", qop="auth"' ],
            401
        ));

        $client = new HttpClientAdapterGuzzle(
            self::$server->getServerRoot(),
            [ 'username' => 'user', 'password' => 'pw', 'bearertoken' => 'token' ]
        );
        // simulate that the authentication scheme has been negotiated with the server of the base URI already
        (new \ReflectionProperty($client, 'authScheme'))->setValue($client, $authScheme);

        $first = $this->nextRequestOffset();
        $client->sendRequest('GET', $redirect);

        $targetRequests = 0;
        for ($i = $first; ($req = self::$server->getRequestByOffset($i)) !== null; ++$i) {
            if ($req->getParsedUri()['path'] === '/challenge') {
                ++$targetRequests;
                $this->assertArrayNotHasKey('AUTHORIZATION', array_change_key_case($req->getHeaders(), CASE_UPPER));
            }
        }
        $this->assertGreaterThan(0, $targetRequests, "Redirect target was not requested");
    }

    /**
     * Returns an absolute URI for the mock server using a different origin than the server root URI (localhost instead
     * of 127.0.0.1).
     */
    private function otherOriginUri(string $path): string
    {
        return 'http://localhost:' . self::$server->getPort() . $path;
    }

    /**
     * Returns the offset of the next request that will be received by the mock server.
     */
    private function nextRequestOffset(): int
    {
        $offset = 0;
        while (self::$server->getRequestByOffset($offset) !== null) {
            ++$offset;
        }
        return $offset;
    }

    /**
     * @param HttpOptions $options
     * @param string $uri The URI to request, relative to $baseUri
     * @param ?string $baseUri The URI of the resource used to send the request, default is the mock server root URI
     * @return array{HEADERS: array, _GET: array, ...}
     */
    private function sendRequest(array $options = [], string $uri = '/res', ?string $baseUri = null): array
    {
        $httpOptions = $options + [
            'username' => 'user',
            'password' => 'pw',
        ];
        $baseUri = $baseUri ?? self::$server->getServerRoot();

        $account = new Account($baseUri, $httpOptions, "", $baseUri);
        $res = new WebDavResource($baseUri, $account);
        $resp = $res->downloadResource($uri);
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
