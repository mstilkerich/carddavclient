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
use MStilkerich\CardDavClient\{Account,WebDavCollection};
use MStilkerich\CardDavClient\Services\Discovery;
use MStilkerich\Tests\CardDavClient\TestInfrastructure;

/**
 * Tests to which servers the credentials are sent during discovery and when querying the children of a collection.
 *
 * The mock web server is reachable via different host names, which are used to simulate different servers:
 *   - localhost: The domain used for discovery
 *   - sub.localhost: A server within the discovery domain
 *   - 127.0.0.1: A server outside the discovery domain
 *
 * The credentials are sent preemptively, so a request carries an Authorization header exactly when the library
 * considers it permitted to send the credentials to the target of the request.
 *
 * @psalm-import-type SrvRecord from Discovery
 */
final class DiscoveryCredentialsTest extends TestCase
{
    private const XMLHDR = [ 'Content-Type' => 'application/xml; charset=utf-8' ];

    /** @var MockWebServer */
    private static $server;

    /** @var int Offset of the first request to the server in the current test */
    private $firstRequest = 0;

    public static function setUpBeforeClass(): void
    {
        self::$server = new MockWebServer();
        self::$server->start();
        self::$server->setDefaultResponse(new Response('Not Found', [], 404));
        TestInfrastructure::init();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        while (self::$server->getRequestByOffset($this->firstRequest) !== null) {
            ++$this->firstRequest;
        }

        // A principal, addressbook home and addressbook on every host; the hrefs are relative to the host
        $all = [ 'localhost', 'sub.localhost', '127.0.0.1' ];
        $this->route('/dav/', array_fill_keys($all, $this->propResponse(
            '/dav/',
            '<d:current-user-principal><d:href>/dav/principal/</d:href></d:current-user-principal>'
        )));
        $this->route('/dav/principal/', array_fill_keys($all, $this->propResponse(
            '/dav/principal/',
            '<card:addressbook-home-set><d:href>/dav/home/</d:href></card:addressbook-home-set>'
        )));
        $this->route('/dav/home/', array_fill_keys($all, $this->childrenResponse('/dav/home/', [ '/dav/home/book/' ])));
        $this->route('/.well-known/carddav', []);
    }

    protected function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
    }

    public function testWellKnownRedirectWithinDomainIsAuthenticated(): void
    {
        $this->route('/.well-known/carddav', [ 'localhost' => $this->redirect($this->uri('sub.localhost', '/dav/')) ]);

        $account = $this->account();
        $abooks = (new TestDiscovery())->discoverAddressbooks($account);

        $this->assertSame([ $this->uri('sub.localhost', '/dav/home/book/') ], $this->uris($abooks));
        $this->assertAuthenticated(['localhost' => true, 'sub.localhost' => true]);
    }

    public function testWellKnownRedirectOutsideDomainIsNotAuthenticated(): void
    {
        $this->route('/.well-known/carddav', [ 'localhost' => $this->redirect($this->uri('127.0.0.1', '/dav/')) ]);

        try {
            (new TestDiscovery())->discoverAddressbooks($this->account());
            $this->fail("Discovery must fail if the addressbook home is outside the discovery domain");
        } catch (\Exception $e) {
            $this->assertStringContainsString('Could not determine the addressbook home', $e->getMessage());
        }

        $this->assertAuthenticated(['localhost' => true, '127.0.0.1' => false]);
    }

    public function testWellKnownRedirectOutsideDomainPermittedByCallback(): void
    {
        $this->route('/.well-known/carddav', [ 'localhost' => $this->redirect($this->uri('127.0.0.1', '/dav/')) ]);

        /** @var \ArrayObject<int, array{string, string}> $calls */
        $calls = new \ArrayObject();
        $callback = function (string $targetUri, string $baseUri) use ($calls): bool {
            $calls->append([ $targetUri, $baseUri ]);
            return true;
        };

        $account = $this->account($callback);
        $abooks = (new TestDiscovery())->discoverAddressbooks($account);

        $this->assertSame([ $this->uri('127.0.0.1', '/dav/home/book/') ], $this->uris($abooks));
        $this->assertAuthenticated(['localhost' => true, '127.0.0.1' => true]);
        $this->assertContains(
            [ $this->uri('127.0.0.1', '/dav/'), 'http://localhost:' . self::$server->getPort() ],
            $calls->getArrayCopy()
        );

        // the discovered addressbooks use the application's account, not the account used internally for discovery
        $this->assertSame($account, $abooks[0]->getAccount());
        $this->assertSame($callback, $account->getAuthOriginCallback());
    }

    /**
     * The discovery policy (credentials permitted within the discovery domain) must not apply to the discovered
     * addressbooks after discovery.
     */
    public function testDiscoveryPolicyDoesNotApplyAfterDiscovery(): void
    {
        $this->route('/.well-known/carddav', [ 'localhost' => $this->redirect($this->uri('sub.localhost', '/dav/')) ]);
        $this->route('/res', [ 'localhost' => new Response('content') ]);

        $abooks = (new TestDiscovery())->discoverAddressbooks($this->account());
        $this->assertCount(1, $abooks);

        $this->firstRequest = $this->nextRequestOffset();
        $abooks[0]->downloadResource($this->uri('localhost', '/res'));
        $this->assertAuthenticated(['localhost' => false]);
    }

    public function testCallbackRestoredAfterFailedDiscovery(): void
    {
        $callback = function (): bool {
            return false;
        };
        $account = $this->account($callback);

        try {
            (new TestDiscovery())->discoverAddressbooks($account);
            $this->fail("Discovery must fail as no server provides the addressbooks");
        } catch (\Exception $e) {
            $this->assertStringContainsString('Could not determine the addressbook home', $e->getMessage());
        }

        $this->assertSame($callback, $account->getAuthOriginCallback());
    }

    public function testSrvTargetWithinDomainIsUsed(): void
    {
        $this->route('/.well-known/carddav', [ 'sub.localhost' => $this->redirect('/dav/') ]);
        $discovery = new TestDiscovery([ '_carddav._tcp.localhost' => [ $this->srv('sub.localhost') ] ]);

        $abooks = $discovery->discoverAddressbooks($this->account());

        $this->assertSame([ $this->uri('sub.localhost', '/dav/home/book/') ], $this->uris($abooks));
        $this->assertAuthenticated(['localhost' => true, 'sub.localhost' => true]);
    }

    public function testSrvTargetOutsideDomainIsIgnored(): void
    {
        $this->route('/.well-known/carddav', [ '127.0.0.1' => $this->redirect('/dav/') ]);
        $discovery = new TestDiscovery([ '_carddav._tcp.localhost' => [ $this->srv('127.0.0.1') ] ]);

        try {
            $discovery->discoverAddressbooks($this->account());
            $this->fail("Discovery must fail if the only server is an SRV target outside the discovery domain");
        } catch (\Exception $e) {
            $this->assertStringContainsString('Could not determine the addressbook home', $e->getMessage());
        }

        $this->assertArrayNotHasKey('127.0.0.1', $this->requestsByHost(), 'SRV target must not be contacted');
    }

    public function testSrvTargetOutsideDomainPermittedByCallback(): void
    {
        $this->route('/.well-known/carddav', [ '127.0.0.1' => $this->redirect('/dav/') ]);
        $discovery = new TestDiscovery([ '_carddav._tcp.localhost' => [ $this->srv('127.0.0.1') ] ]);

        /** @var \ArrayObject<int, array{string, string}> $calls */
        $calls = new \ArrayObject();
        $callback = function (string $targetUri, string $baseUri) use ($calls): bool {
            $calls->append([ $targetUri, $baseUri ]);
            return true;
        };

        $abooks = $discovery->discoverAddressbooks($this->account($callback));

        $this->assertSame([ $this->uri('127.0.0.1', '/dav/home/book/') ], $this->uris($abooks));
        $this->assertAuthenticated(['localhost' => true, '127.0.0.1' => true]);
        $port = self::$server->getPort();
        $this->assertSame([ [ "http://127.0.0.1:$port", "http://localhost:$port" ] ], $calls->getArrayCopy());
    }

    public function testNoDnsLookupForIpAddress(): void
    {
        $this->route('/.well-known/carddav', [ '127.0.0.1' => $this->redirect('/dav/') ]);
        // if the SRV record was used, its target (permitted by the callback) would be tried before the well-known URI
        // of the discovery URI's host
        $discovery = new TestDiscovery([ '_carddav._tcp.127.0.0.1' => [ $this->srv('localhost') ] ]);

        $account = new Account(
            'http://127.0.0.1:' . self::$server->getPort(),
            [
                'username' => 'user',
                'password' => 'pw',
                'preemptive_basic_auth' => true,
                'auth_origin_callback' => function (): bool {
                    return true;
                },
            ]
        );
        $abooks = $discovery->discoverAddressbooks($account);

        $this->assertSame([ $this->uri('127.0.0.1', '/dav/home/book/') ], $this->uris($abooks));
        $this->assertAuthenticated(['127.0.0.1' => true]);
        $this->assertSame([], $discovery->queries);
    }

    public function testBuiltinServerDomainIsAuthenticated(): void
    {
        // The builtin server for sub.localhost is localhost, which redirects to dav.localhost. dav.localhost is outside
        // the discovery domain, but within the domain of the builtin server.
        $this->route('/.well-known/carddav', [ 'localhost' => $this->redirect($this->uri('dav.localhost', '/dav/')) ]);
        $this->route('/dav/', [ 'dav.localhost' => $this->propResponse(
            '/dav/',
            '<d:current-user-principal><d:href>/dav/principal/</d:href></d:current-user-principal>'
        ) ]);
        $this->route('/dav/principal/', [ 'dav.localhost' => $this->propResponse(
            '/dav/principal/',
            '<card:addressbook-home-set><d:href>/dav/home/</d:href></card:addressbook-home-set>'
        ) ]);
        $this->route('/dav/home/', [ 'dav.localhost' => $this->childrenResponse('/dav/home/', [ '/dav/home/book/' ]) ]);

        $account = new Account(
            'http://sub.localhost:' . self::$server->getPort(),
            [ 'username' => 'user', 'password' => 'pw', 'preemptive_basic_auth' => true ]
        );
        $abooks = (new TestDiscovery())->discoverAddressbooks($account);

        $this->assertSame([ $this->uri('dav.localhost', '/dav/home/book/') ], $this->uris($abooks));
        $this->assertAuthenticated(['sub.localhost' => true, 'localhost' => true, 'dav.localhost' => true]);
    }

    public function testAddressbookHomeOutsideDomainIsIgnored(): void
    {
        $this->route('/.well-known/carddav', [ 'localhost' => $this->redirect('/dav/') ]);
        $this->route('/dav/principal/', [ 'localhost' => $this->propResponse(
            '/dav/principal/',
            '<card:addressbook-home-set><d:href>' . $this->uri('127.0.0.1', '/dav/home/')
            . '</d:href></card:addressbook-home-set>'
        ) ]);

        try {
            (new TestDiscovery())->discoverAddressbooks($this->account());
            $this->fail("Discovery must fail if the addressbook home is outside the discovery domain");
        } catch (\Exception $e) {
            $this->assertStringContainsString('Could not determine the addressbook home', $e->getMessage());
        }

        $this->assertArrayNotHasKey('127.0.0.1', $this->requestsByHost(), 'Foreign home must not be contacted');
    }

    public function testChildrenAreDirectMembersOnly(): void
    {
        $foreign = $this->uri('localhost', '/coll/x/');
        $this->route('/coll/', [ '127.0.0.1' => $this->childrenResponse(
            '/coll/',
            [ '/coll/a/', '/coll/%62/', '/coll/a/nested/', '/other/', $foreign ]
        ) ]);

        $coll = new WebDavCollection($this->uri('127.0.0.1', '/coll/'), $this->account());
        $children = $coll->getChildren();

        $this->assertSame(
            [ $this->uri('127.0.0.1', '/coll/a/'), $this->uri('127.0.0.1', '/coll/%62/') ],
            $this->uris($children)
        );
        $ignored = [ $this->uri('127.0.0.1', '/coll/a/nested/'), $this->uri('127.0.0.1', '/other/'), $foreign ];
        foreach ($ignored as $uri) {
            TestInfrastructure::logger()->expectMessage('warning', "Ignoring $uri: not a member");
        }
    }

    public function testChildrenOfRedirectedCollection(): void
    {
        $this->route('/old/', [ '127.0.0.1' => $this->redirect('/coll/') ]);
        $this->route('/coll/', [ '127.0.0.1' => $this->childrenResponse('/coll/', [ '/coll/a/' ]) ]);

        $coll = new WebDavCollection($this->uri('127.0.0.1', '/old/'), $this->account());

        // the collection itself (at its new location) must not be reported as a child
        $this->assertSame([ $this->uri('127.0.0.1', '/coll/a/') ], $this->uris($coll->getChildren()));
    }

    public function testChildrenOfCollectionRedirectedToDifferentOriginAreIgnored(): void
    {
        $target = $this->uri('localhost', '/coll/');
        $this->route('/old/', [ '127.0.0.1' => $this->redirect($target) ]);
        $this->route('/coll/', [ 'localhost' => $this->childrenResponse('/coll/', [ '/coll/a/' ]) ]);

        $coll = new WebDavCollection($this->uri('127.0.0.1', '/old/'), $this->account());

        $this->assertSame([], $coll->getChildren());
        TestInfrastructure::logger()->expectMessage('warning', "redirected to $target");
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
     * Creates the account used by the tests, with discovery URI http://localhost:<port>.
     *
     * @param null|callable(string, string):bool $callback
     */
    private function account(?callable $callback = null): Account
    {
        $httpOptions = [ 'username' => 'user', 'password' => 'pw', 'preemptive_basic_auth' => true ];
        if (isset($callback)) {
            $httpOptions['auth_origin_callback'] = $callback;
        }
        return new Account('http://localhost:' . self::$server->getPort(), $httpOptions);
    }

    /**
     * Asserts which hosts received requests in the current test, and whether these requests were authenticated.
     *
     * @param array<string, bool> $expected Maps host names to whether the requests to that host were authenticated
     */
    private function assertAuthenticated(array $expected): void
    {
        $requests = $this->requestsByHost();
        ksort($requests);
        ksort($expected);

        $actual = [];
        foreach ($requests as $host => $authFlags) {
            $actual[$host] = array_values(array_unique($authFlags)) === [ true ] ? true
                : (array_values(array_unique($authFlags)) === [ false ] ? false : 'mixed');
        }
        $this->assertSame($expected, $actual);
    }

    /**
     * Returns the hosts that received requests in the current test.
     *
     * @return array<string, list<bool>> Maps host names to a list with an entry per request, true if authenticated
     */
    private function requestsByHost(): array
    {
        $result = [];
        $offset = $this->firstRequest;
        while (($request = self::$server->getRequestByOffset($offset++)) !== null) {
            $headers = array_change_key_case($request->getHeaders(), CASE_LOWER);
            $host = preg_replace('/:\d+$/', '', (string) ($headers['host'] ?? '')) ?? '';
            $result[$host][] = isset($headers['authorization']);
        }
        return $result;
    }

    /**
     * @param array<string, Response> $responses Maps host names to the response for that host
     */
    private function route(string $path, array $responses): void
    {
        self::$server->setResponseOfPath($path, new HostRoutedResponse($responses));
    }

    private function uri(string $host, string $path): string
    {
        return "http://$host:" . self::$server->getPort() . $path;
    }

    /**
     * @param list<\MStilkerich\CardDavClient\WebDavResource> $resources
     * @return list<string>
     */
    private function uris(array $resources): array
    {
        return array_map(
            function (\MStilkerich\CardDavClient\WebDavResource $r): string {
                return $r->getUri();
            },
            $resources
        );
    }

    /**
     * @psalm-return SrvRecord
     */
    private function srv(string $target): array
    {
        return [ 'pri' => 0, 'weight' => 0, 'target' => $target, 'port' => self::$server->getPort() ];
    }

    private function redirect(string $location): Response
    {
        return new Response('', [ 'Location' => $location ], 301);
    }

    private function propResponse(string $href, string $propXml): Response
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . "<d:response><d:href>$href</d:href><d:propstat><d:prop>$propXml</d:prop>"
            . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
        return new Response($body, self::XMLHDR, 207);
    }

    /**
     * Response to a Depth: 1 PROPFIND on a collection that contains the given addressbooks.
     *
     * @param list<string> $children
     */
    private function childrenResponse(string $collHref, array $children): Response
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . "<d:response><d:href>$collHref</d:href><d:propstat><d:prop>"
            . '<d:resourcetype><d:collection/></d:resourcetype></d:prop>'
            . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
        foreach ($children as $child) {
            $body .= "<d:response><d:href>$child</d:href><d:propstat><d:prop>"
                . '<d:resourcetype><d:collection/><card:addressbook/></d:resourcetype></d:prop>'
                . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
        }
        $body .= '</d:multistatus>';
        return new Response($body, self::XMLHDR, 207);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
