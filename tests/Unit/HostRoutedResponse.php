<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Unit;

use donatj\MockWebServer\{RequestInfo,Response,ResponseInterface};

/**
 * A response of the mock web server that depends on the host name the request was sent to.
 *
 * This allows to simulate different servers (e.g. localhost, sub.localhost, 127.0.0.1) with a single mock web server.
 * If no response is defined for the host of a request, a 404 response is returned.
 *
 * The methods of ResponseInterface are marked internal by the mock web server library, but a response that delegates
 * to other responses (like ResponseByMethod of the library) has to call them.
 * @psalm-suppress InternalMethod
 */
final class HostRoutedResponse implements ResponseInterface
{
    /** @var array<string, ResponseInterface> Maps host names (without port) to the response for that host */
    private $responses;

    /**
     * @param array<string, ResponseInterface> $responses Maps host names (without port) to the response for that host
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function getRef(): string
    {
        return md5(serialize($this->responses));
    }

    public function getBody(RequestInfo $request): string
    {
        return $this->getResponse($request)->getBody($request);
    }

    public function getHeaders(RequestInfo $request): array
    {
        return $this->getResponse($request)->getHeaders($request);
    }

    public function getStatus(RequestInfo $request): int
    {
        return $this->getResponse($request)->getStatus($request);
    }

    private function getResponse(RequestInfo $request): ResponseInterface
    {
        $headers = array_change_key_case($request->getHeaders(), CASE_LOWER);
        $host = preg_replace('/:\d+$/', '', (string) ($headers['host'] ?? '')) ?? '';
        return $this->responses[$host] ?? new Response('Not Found', [], 404);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
