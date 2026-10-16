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
use donatj\MockWebServer\{MockWebServer,Response,ResponseByMethod,ResponseStack};
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavClient\Services\Sync;
use MStilkerich\Tests\CardDavClient\TestInfrastructure;

/**
 * Unit tests for the result limiting (RFC 6578 DAV:limit) of the Sync service.
 */
final class SyncTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
    }

    public function testLimitedSyncFetchesAllBatches(): void
    {
        self::$server->setResponseOfPath('/abook/', new ResponseByMethod([
            'PROPFIND' => $this->propfindResponse(true, false),
            'REPORT' => new ResponseStack(
                $this->syncResponse('t1', '/abook/c1.vcf', true),
                $this->syncResponse('t2', '/abook/c2.vcf', true),
                $this->syncResponse('t3', '/abook/c3.vcf', false)
            ),
        ]));

        $handler = new RecordingSyncHandler();
        $synctoken = (new Sync())->synchronize($this->abook(), $handler, [], 't0', 1);

        $this->assertSame('t3', $synctoken);
        $this->assertSame(['D /abook/c1.vcf', 'D /abook/c2.vcf', 'D /abook/c3.vcf', 'F'], $handler->ops);

        $reports = $this->reportRequests();
        $this->assertCount(3, $reports);
        foreach ($reports as $i => $body) {
            $this->assertMatchesRegularExpression(
                ';<DAV:limit>\s*<DAV:nresults>1</DAV:nresults>\s*</DAV:limit>;',
                $body
            );
            $this->assertStringContainsString("<DAV:sync-token>t$i</DAV:sync-token>", $body);
        }
    }

    public function testLimitedSyncIsNotCappedToTenBatches(): void
    {
        $responses = [];
        for ($i = 1; $i <= 12; ++$i) {
            $responses[] = $this->syncResponse("t$i", "/abook/c$i.vcf", $i < 12);
        }
        self::$server->setResponseOfPath('/abook/', new ResponseByMethod([
            'PROPFIND' => $this->propfindResponse(true, false),
            'REPORT' => new ResponseStack(...$responses),
        ]));

        $handler = new RecordingSyncHandler();
        $synctoken = (new Sync())->synchronize($this->abook(), $handler, [], 't0', 1);

        $this->assertSame('t12', $synctoken);
        $this->assertCount(12, array_filter($handler->ops, fn (string $op) => $op[0] === 'D'));
    }

    public function testLimitedSyncStopsIfSyncTokenDoesNotAdvance(): void
    {
        self::$server->setResponseOfPath('/abook/', new ResponseByMethod([
            'PROPFIND' => $this->propfindResponse(true, false),
            'REPORT' => new ResponseStack(
                $this->syncResponse('t1', '/abook/c1.vcf', true),
                $this->syncResponse('t1', '/abook/c1.vcf', true)
            ),
        ]));

        $handler = new RecordingSyncHandler();
        $synctoken = (new Sync())->synchronize($this->abook(), $handler, [], 't0', 1);

        $this->assertSame('t1', $synctoken);
        $this->assertCount(2, $this->reportRequests());
        TestInfrastructure::logger()->expectMessage('warning', 'sync-token unchanged');
    }

    public function testRejectedLimitFallsBackToLimitedETagSync(): void
    {
        $error = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:error xmlns:d="DAV:"><d:number-of-matches-within-limits/></d:error>';

        self::$server->setResponseOfPath('/abook/', new ResponseByMethod([
            'PROPFIND' => new ResponseStack($this->propfindResponse(true, true), $this->listingResponse()),
            'REPORT' => new ResponseStack(
                new Response($error, self::XMLHDR, 507),
                $this->multigetResponse(['c2', 'c3']),
                $this->multigetResponse(['c4'])
            ),
        ]));

        // the current sync-token of the collection (t0) must differ from the previous one, or the fallback considers
        // the addressbook up to date
        $handler = new RecordingSyncHandler(self::LOCAL_ETAGS);
        $synctoken = (new Sync())->synchronize($this->abook(), $handler, [], 'tprev', 2);

        $this->assertSame('tnew', $synctoken);
        $this->assertSame(self::EXPECTED_ETAGSYNC_OPS, $handler->ops);

        $reports = $this->reportRequests();
        $this->assertCount(3, $reports);
        $this->assertStringContainsString('<DAV:limit>', $reports[0]);
        $this->assertMultigetHrefs(['c2', 'c3'], $reports[1]);
        $this->assertMultigetHrefs(['c4'], $reports[2]);
        TestInfrastructure::logger()->expectMessage('error', 'sync-collection REPORT produced exception');
    }

    public function testETagSyncWithoutSyncCollectionHonorsLimit(): void
    {
        self::$server->setResponseOfPath('/abook/', new ResponseByMethod([
            'PROPFIND' => new ResponseStack($this->propfindResponse(false, true), $this->listingResponse()),
            'REPORT' => new ResponseStack($this->multigetResponse(['c2', 'c3']), $this->multigetResponse(['c4'])),
        ]));

        $handler = new RecordingSyncHandler(self::LOCAL_ETAGS);
        $synctoken = (new Sync())->synchronize($this->abook(), $handler, [], 'c0', 2);

        $this->assertSame('tnew', $synctoken);
        $this->assertSame(self::EXPECTED_ETAGSYNC_OPS, $handler->ops);

        $reports = $this->reportRequests();
        $this->assertCount(2, $reports);
        $this->assertMultigetHrefs(['c2', 'c3'], $reports[0]);
        $this->assertMultigetHrefs(['c4'], $reports[1]);
    }

    public function testETagSyncWithoutLimitIsSingleBatch(): void
    {
        self::$server->setResponseOfPath('/abook/', new ResponseByMethod([
            'PROPFIND' => new ResponseStack($this->propfindResponse(false, true), $this->listingResponse()),
            'REPORT' => $this->multigetResponse(['c2', 'c3', 'c4']),
        ]));

        $handler = new RecordingSyncHandler(self::LOCAL_ETAGS);
        (new Sync())->synchronize($this->abook(), $handler, [], 'c0');

        $this->assertSame(
            ['D /abook/gone.vcf', 'C /abook/c2.vcf', 'C /abook/c3.vcf', 'C /abook/c4.vcf', 'F'],
            $handler->ops
        );
        $this->assertCount(1, $this->reportRequests());
    }

    /**
     * Local state for the ETag-based sync tests: c1 unchanged, c2 changed, gone.vcf deleted on the server.
     * The server additionally has the new cards c3 and c4.
     */
    private const LOCAL_ETAGS = [ '/abook/c1.vcf' => '"e1"', '/abook/c2.vcf' => '"old"', '/abook/gone.vcf' => '"x"' ];

    /** Expected handler operations of the ETag-based sync tests with limit 2 */
    private const EXPECTED_ETAGSYNC_OPS = [
        'D /abook/gone.vcf', 'C /abook/c2.vcf', 'C /abook/c3.vcf', 'C /abook/c4.vcf', 'F'
    ];

    /**
     * @param list<string> $expCards
     */
    private function assertMultigetHrefs(array $expCards, string $body): void
    {
        $this->assertStringContainsString('addressbook-multiget', $body);
        preg_match_all(';<DAV:href>/abook/(\w+)\.vcf</DAV:href>;', $body, $matches);
        $this->assertSame($expCards, $matches[1]);
    }

    private function abook(): AddressbookCollection
    {
        $account = new Account(self::$server->getServerRoot(), ['username' => 'user', 'password' => 'pw']);
        return new AddressbookCollection(self::$server->getServerRoot() . '/abook/', $account);
    }

    /**
     * Returns the bodies of the REPORT requests received by the server in the current test, oldest first.
     *
     * @return list<string>
     */
    private function reportRequests(): array
    {
        $bodies = [];
        $offset = $this->firstRequest;
        while (($request = self::$server->getRequestByOffset($offset++)) !== null) {
            if ($request->getRequestMethod() === 'REPORT') {
                $bodies[] = $request->getInput();
            }
        }
        return $bodies;
    }

    /**
     * Response to the PROPFIND for the properties of the addressbook collection.
     */
    private function propfindResponse(bool $syncColl, bool $multiGet): Response
    {
        $reports = '';
        if ($syncColl) {
            $reports .= '<d:supported-report><d:report><d:sync-collection/></d:report></d:supported-report>';
        }
        if ($multiGet) {
            $reports .= '<d:supported-report><d:report><card:addressbook-multiget/></d:report></d:supported-report>';
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"'
            . ' xmlns:cs="http://calendarserver.org/ns/">'
            . '<d:response><d:href>/abook/</d:href><d:propstat><d:prop>'
            . '<d:resourcetype><d:collection/><card:addressbook/></d:resourcetype>'
            . ($syncColl ? '<d:sync-token>t0</d:sync-token>' : '<cs:getctag>c1</cs:getctag>')
            . "<d:supported-report-set>$reports</d:supported-report-set>"
            . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
            . '</d:multistatus>';
        return new Response($body, self::XMLHDR, 207);
    }

    /**
     * Response to the Depth: 1 PROPFIND of the ETag-based sync: cards c1 - c4 with ETags "e1" - "e4".
     */
    private function listingResponse(): Response
    {
        $body = '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:">'
            . '<d:response><d:href>/abook/</d:href><d:propstat><d:prop><d:sync-token>tnew</d:sync-token></d:prop>'
            . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
        for ($i = 1; $i <= 4; ++$i) {
            $body .= "<d:response><d:href>/abook/c$i.vcf</d:href><d:propstat><d:prop>"
                . "<d:getetag>\"e$i\"</d:getetag></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>"
                . '</d:response>';
        }
        $body .= '</d:multistatus>';
        return new Response($body, self::XMLHDR, 207);
    }

    /**
     * @param list<string> $cards
     */
    private function multigetResponse(array $cards): Response
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">';
        foreach ($cards as $card) {
            $etag = '"e' . substr($card, 1) . '"';
            $vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:$card\r\nFN:$card\r\nEND:VCARD\r\n";
            $body .= "<d:response><d:href>/abook/$card.vcf</d:href><d:propstat><d:prop>"
                . "<d:getetag>$etag</d:getetag><card:address-data>$vcf</card:address-data>"
                . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
        }
        $body .= '</d:multistatus>';
        return new Response($body, self::XMLHDR, 207);
    }

    private function syncResponse(string $token, string $deletedUri, bool $truncated): Response
    {
        $body = '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:">'
            . "<d:response><d:href>$deletedUri</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>";
        if ($truncated) {
            $body .= '<d:response><d:href>/abook/</d:href>'
                . '<d:status>HTTP/1.1 507 Insufficient Storage</d:status></d:response>';
        }
        $body .= "<d:sync-token>$token</d:sync-token></d:multistatus>";
        return new Response($body, self::XMLHDR, 207);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
