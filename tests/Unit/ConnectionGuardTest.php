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
use donatj\MockWebServer\Responses\DefaultResponse;
use GuzzleHttp\Promise\{FulfilledPromise,PromiseInterface};
use GuzzleHttp\Psr7\{Request,Uri};
use Psr\Http\Message\RequestInterface;
use MStilkerich\CardDavClient\{Account,WebDavResource};
use MStilkerich\CardDavClient\Exception\{DiscoveryException,ForbiddenHostException,HttpStatusException};
use MStilkerich\CardDavClient\Services\Discovery;
use MStilkerich\Tests\CardDavClient\TestInfrastructure;

/**
 * @psalm-import-type HttpOptions from Account
 */
final class ConnectionGuardTest extends TestCase
{
    /** @var MockWebServer */
    private static $server;

    /** @var list<array{string, int, list<string>}> The calls of the callback created by recordingCallback() */
    private $calls = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = new MockWebServer();
        self::$server->start();
        TestInfrastructure::init();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        $this->calls = [];
    }

    /**
     * @return array<string, array{string, string, int, list<string>}>
     */
    public static function uriProvider(): array
    {
        return [
            'IPv4' => [ 'https://93.184.216.34/', '93.184.216.34', 443, [ '93.184.216.34' ] ],
            'IPv4 http' => [ 'http://127.0.0.1/', '127.0.0.1', 80, [ '127.0.0.1' ] ],
            'IPv4 with port' => [ 'http://127.0.0.1:8080/', '127.0.0.1', 8080, [ '127.0.0.1' ] ],
            'IPv6' => [ 'https://[FD00::5]:8443/', 'fd00::5', 8443, [ 'fd00::5' ] ],
            'IPv6 uncommon notation' => [ 'http://[0:0:0:0:0:0:0:1]/', '::1', 80, [ '::1' ] ],
            'IPv4-mapped IPv6' => [ 'http://[::ffff:127.0.0.1]/', '127.0.0.1', 80, [ '127.0.0.1' ] ],
            'IPv4-mapped IPv6 hex' => [ 'http://[::ffff:7f00:1]/', '127.0.0.1', 80, [ '127.0.0.1' ] ],
            'hostname' => [ 'https://Public.Example.com./', 'public.example.com', 443, [ '93.184.216.34' ] ],
            'hostname multiple addresses' => [
                'https://dual.example.com:8443/', 'dual.example.com', 8443, [ '93.184.216.34', '2606:2800:220:1::1' ]
            ],
            'hostname address notations' => [
                'https://notation.example.com/', 'notation.example.com', 443, [ '127.0.0.1', '::1', 'fd00::5' ]
            ],
            'unresolvable hostname' => [ 'https://nonexistent.example.com/', 'nonexistent.example.com', 443, [] ],
            'decimal IPv4 notation' => [ 'http://2130706433/', '2130706433', 80, [ '127.0.0.1' ] ],
        ];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('uriProvider')]
    public function testCallbackIsGivenTargetOfRequest(string $uri, string $host, int $port, array $addresses): void
    {
        $guard = new TestConnectionGuard($this->recordingCallback(true));
        $guard->checkUri(new Uri($uri));
        $this->assertSame([ [ $host, $port, $addresses ] ], $this->calls);
    }

    public function testRequestIsRefusedIfCallbackDoesNotPermit(): void
    {
        $guard = new TestConnectionGuard($this->recordingCallback(false));

        try {
            $guard->checkUri(new Uri('https://public.example.com/'));
            $this->fail('Request expected to be refused');
        } catch (ForbiddenHostException $e) {
            $this->assertSame('Access to host public.example.com is not permitted', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function zoneIdentifierProvider(): array
    {
        return [
            'link-local address' => [ '[fe80::1%25eth0]', 'fe80::1', [ 'fe80::1' ] ],
            'uncommon notation' => [ '[FE80:0:0:0:0:0:0:1%25eth0]', 'fe80::1', [ 'fe80::1' ] ],
            'not percent-encoded' => [ '[fe80::1%eth0]', 'fe80::1', [ 'fe80::1' ] ],
            // not an IPv6 address: treated as a hostname, which cannot be resolved
            'IPv4 address' => [ '127.0.0.1%25eth0', '127.0.0.1%25eth0', [] ],
            'IPv4-mapped IPv6 address' => [ '[::ffff:127.0.0.1%25eth0]', '::ffff:127.0.0.1%25eth0', [] ],
            'hostname' => [ 'public.example.com%25eth0', 'public.example.com%25eth0', [] ],
        ];
    }

    /**
     * The URI is a stub as some versions of the PSR-7 implementation refuse URIs with a zone identifier.
     *
     * @param list<string> $expAddresses
     */
    #[DataProvider('zoneIdentifierProvider')]
    public function testZoneIdentifierIsNotPartOfCheckedAddress(
        string $host,
        string $expHost,
        array $expAddresses
    ): void {
        $uri = $this->createStub(\Psr\Http\Message\UriInterface::class);
        $uri->method('getHost')->willReturn($host);
        $uri->method('getScheme')->willReturn('http');

        $guard = new TestConnectionGuard($this->recordingCallback(true));
        $this->assertNull($guard->checkUri($uri)); // no addresses to bind the HTTP client to
        $this->assertSame([ [ $expHost, 80, $expAddresses ] ], $this->calls);
    }

    public function testRequestWithoutHostIsRefused(): void
    {
        $guard = new TestConnectionGuard($this->recordingCallback(true));

        try {
            $guard->checkUri(new Uri('/path/only'));
            $this->fail('Request expected to be refused');
        } catch (ForbiddenHostException $e) {
            $this->assertSame([], $this->calls);
        }
    }

    public function testResolvedAddressesArePinned(): void
    {
        $guard = new TestConnectionGuard($this->recordingCallback(true));

        $this->assertSame(
            [ 'dual.example.com:8443:93.184.216.34,2606:2800:220:1::1' ],
            $this->middlewareCurlOptions($guard, 'https://Dual.Example.com:8443/')[CURLOPT_RESOLVE]
        );
        $this->assertSame(
            [ 'notation.example.com:80:127.0.0.1,::1,fd00::5' ],
            $this->middlewareCurlOptions($guard, 'http://notation.example.com/')[CURLOPT_RESOLVE]
        );
        $this->assertSame(
            [ 'public.example.com:443:93.184.216.34', 'public.example.com.:443:93.184.216.34' ],
            $this->middlewareCurlOptions($guard, 'https://public.example.com./')[CURLOPT_RESOLVE]
        );

        // other curl options are retained
        $curlOpts = $this->middlewareCurlOptions($guard, 'http://dual.example.com/', [ CURLOPT_HTTPAUTH => 1 ]);
        $this->assertSame(1, $curlOpts[CURLOPT_HTTPAUTH]);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $curlOpts);

        // no pinning for IP addresses and hostnames that cannot be resolved
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $this->middlewareCurlOptions($guard, 'https://10.0.0.5/'));
        $this->assertArrayNotHasKey(
            CURLOPT_RESOLVE,
            $this->middlewareCurlOptions($guard, 'https://nonexistent.example.com/')
        );
    }

    /**
     * The name used in this test is only resolvable by the guard, so the request can only succeed if the HTTP client
     * uses the addresses checked by the guard.
     */
    public function testHttpClientConnectsToCheckedAddresses(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('Requires the curl extension');
        }

        $guard = new TestConnectionGuard($this->recordingCallback(true));
        $stack = \GuzzleHttp\HandlerStack::create();
        $stack->push($guard->middleware());
        $client = new \GuzzleHttp\Client([ 'handler' => $stack ]);

        $port = self::$server->getPort();
        $response = $client->request('GET', "http://loopback.example.com:$port/res");
        $json = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($json);
        $this->assertSame('/res', $json['REQUEST_URI']);
    }

    public function testRefusedRequestIsNotPassedToHandler(): void
    {
        $guard = new TestConnectionGuard($this->recordingCallback(false));
        $handler = ($guard->middleware())(function (): PromiseInterface {
            $this->fail("Handler must not be called for refused request");
        });

        $this->expectException(ForbiddenHostException::class);
        $handler(new Request('GET', 'https://public.example.com/'), []);
    }

    public function testNameResolutionIsCached(): void
    {
        $guard = new TestConnectionGuard($this->recordingCallback(true));
        $guard->checkUri(new Uri('https://public.example.com/a'));
        $guard->checkUri(new Uri('https://public.example.com/b'));
        $guard->checkUri(new Uri('http://public.example.com:8080/c'));
        $this->assertSame(1, $guard->numLookups);
        $this->assertCount(3, $this->calls);
    }

    public function testRequestsUnrestrictedWithoutCallback(): void
    {
        $json = $this->sendRequest([]);
        $this->assertSame('/res', $json['REQUEST_URI']);
    }

    public function testRequestRefusedByCallback(): void
    {
        try {
            $this->sendRequest([ 'allow_host_callback' => $this->recordingCallback(false) ]);
            $this->fail('Request expected to be refused');
        } catch (ForbiddenHostException $e) {
            $this->assertSame([ [ '127.0.0.1', self::$server->getPort(), [ '127.0.0.1' ] ] ], $this->calls);
        }
    }

    public function testRequestPermittedByCallback(): void
    {
        $json = $this->sendRequest([ 'allow_host_callback' => $this->recordingCallback(true) ]);
        $this->assertSame('/res', $json['REQUEST_URI']);
        $this->assertSame([ [ '127.0.0.1', self::$server->getPort(), [ '127.0.0.1' ] ] ], $this->calls);
    }

    public function testRequestToHostnameIsResolvedForCallback(): void
    {
        $port = self::$server->getPort();
        $httpOptions = [ 'allow_host_callback' => $this->recordingCallback(true) ];
        $json = $this->sendRequest($httpOptions, "http://localhost:$port");
        $this->assertSame('/res', $json['REQUEST_URI']);

        $this->assertCount(1, $this->calls);
        $this->assertSame('localhost', $this->calls[0][0]);
        $this->assertSame($port, $this->calls[0][1]);
        $this->assertContains('127.0.0.1', $this->calls[0][2]);
    }

    public function testCallbackIsConsultedForRedirectTarget(): void
    {
        $port = self::$server->getPort();
        $otherPort = $port + 1;
        $response = new Response('', [ 'Location' => "http://127.0.0.1:$otherPort/" ], 302);
        self::$server->setResponseOfPath('/redir', $response);

        $callback = function (string $host, int $connPort, array $addresses) use ($port): bool {
            /** @psalm-var list<string> $addresses */
            $this->calls[] = [ $host, $connPort, $addresses ];
            return $connPort === $port;
        };

        try {
            $this->sendRequest([ 'allow_host_callback' => $callback ], null, '/redir');
            $this->fail('Redirect expected to be refused');
        } catch (ForbiddenHostException $e) {
            $expCalls = [ [ '127.0.0.1', $port, [ '127.0.0.1' ] ], [ '127.0.0.1', $otherPort, [ '127.0.0.1' ] ] ];
            $this->assertSame($expCalls, $this->calls);
        }
    }

    public function testDiscoveryReportsRefusedRequests(): void
    {
        $httpOptions = [ 'allow_host_callback' => $this->recordingCallback(false) ];
        $account = new Account(self::$server->getServerRoot(), $httpOptions);

        try {
            (new Discovery())->discoverAddressbooks($account);
            $this->fail("Discovery expected to fail");
        } catch (DiscoveryException $e) {
            $this->assertSame('Could not determine the addressbook home', $e->getMessage());

            // the given URI, the well-known URI and the root path are tried, each of which is refused
            $root = self::$server->getServerRoot();
            $errors = $e->getErrors();
            $this->assertSame([ "$root/", "$root/.well-known/carddav", "$root/" ], array_column($errors, 'uri'));
            foreach ($errors as $error) {
                $this->assertInstanceOf(ForbiddenHostException::class, $error['exception']);
                $this->assertSame('Access to host 127.0.0.1 is not permitted', $error['exception']->getMessage());
            }
            $this->assertSame($errors[0]['exception'], $e->getPrevious());
        }
    }

    public function testDiscoveryReportsOtherErrorsLikeRefusedRequests(): void
    {
        self::$server->setDefaultResponse(new Response('', [], 401));
        $httpOptions = [ 'allow_host_callback' => $this->recordingCallback(true) ];
        $account = new Account(self::$server->getServerRoot(), $httpOptions);

        try {
            (new Discovery())->discoverAddressbooks($account);
            $this->fail("Discovery expected to fail");
        } catch (DiscoveryException $e) {
            $errors = $e->getErrors();
            $this->assertCount(3, $errors);
            foreach ($errors as $error) {
                $this->assertInstanceOf(HttpStatusException::class, $error['exception']);
                $this->assertSame(401, $error['exception']->getStatusCode());
            }
            $this->assertSame($errors[0]['exception'], $e->getPrevious());
        } finally {
            self::$server->setDefaultResponse(new DefaultResponse());
        }
    }

    public function testCallbackIsNotSerializedWithAccount(): void
    {
        $httpOptions = [ 'username' => 'u', 'allow_host_callback' => $this->recordingCallback(true) ];
        $account = new Account("example.com", $httpOptions);
        $expSerial = [ 'discoveryUri' => 'example.com', 'baseUrl' => null, 'username' => 'u' ];
        $this->assertSame($expSerial, $account->jsonSerialize());
    }

    /**
     * Provides a callback that records its calls in $this->calls and returns the given result.
     *
     * @return callable(string, int, list<string>): bool
     */
    private function recordingCallback(bool $result): callable
    {
        return function (string $host, int $port, array $addresses) use ($result): bool {
            $this->calls[] = [ $host, $port, $addresses ];
            return $result;
        };
    }

    /**
     * Passes a request through the middleware of the guard and returns the curl options passed to the handler.
     *
     * @param array<int, mixed> $curlOptions Curl options passed to the middleware
     * @return array<int, mixed>
     */
    private function middlewareCurlOptions(TestConnectionGuard $guard, string $uri, array $curlOptions = []): array
    {
        $result = [];
        $handler = ($guard->middleware())(
            function (RequestInterface $_request, array $options) use (&$result): PromiseInterface {
                $this->assertIsArray($options['curl'] ?? []);
                /** @var array<int, mixed> */
                $result = $options['curl'] ?? [];
                /** @var PromiseInterface<\Psr\Http\Message\ResponseInterface> */
                $promise = new FulfilledPromise(new \GuzzleHttp\Psr7\Response());
                return $promise;
            }
        );

        $handler(new Request('GET', $uri), empty($curlOptions) ? [] : [ 'curl' => $curlOptions ]);
        return $result;
    }

    /**
     * @param HttpOptions $httpOptions
     * @return array<array-key, mixed>
     */
    private function sendRequest(array $httpOptions, ?string $baseUri = null, string $uri = '/res'): array
    {
        $baseUri = $baseUri ?? self::$server->getServerRoot();

        $account = new Account($baseUri, $httpOptions, "", $baseUri);
        $res = new WebDavResource($baseUri, $account);
        $resp = $res->downloadResource($uri);
        $json = json_decode($resp['body'], true);
        $this->assertIsArray($json);
        return $json;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
