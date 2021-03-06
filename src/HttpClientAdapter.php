<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (C) 2020 Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of PHP-CardDavClient.
 *
 * PHP-CardDavClient is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PHP-CardDavClient is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PHP-CardDavClient.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Psr\Http\Message\ResponseInterface as Psr7Response;

/**
 * Abstract base class for the internal HTTP client adapter.
 *
 * This class intends to decouple the rest of this library from the underlying HTTP client library to allow for
 * future replacement.
 *
 * We aim at staying close to the PSR-18 definition of the Http ClientInterface, however, because Guzzle does currently
 * not expose this interface (in particular its Psr7Request creation), compliance would mean to define an own
 * implementation of the Psr7 Request Interface to create request objects, that would have to be deconstructed when
 * interaction with Guzzle again.
 *
 * So for now, this is not compliant with PSR-18 for simplicity, but we aim at staying close to the definition
 * considering a potential later refactoring.
 *
 * @psalm-type RequestOptions = array {
 *   allow_redirects?: bool,
 *   body?: string,
 *   headers?: array<string, string | list<string>>
 * }
 *
 * @package Internal\Communication
 */
abstract class HttpClientAdapter
{
    /**
     * The base URI for requests.
     * @var string
     */
    protected $baseUri;

    /**
     * Sends an HTTP request and returns a PSR-7 response.
     *
     * @param string $method The request method (GET, PROPFIND, etc.)
     * @param string $uri The target URI. If relative, taken relative to the internal base URI of the HTTP client
     * @psalm-param RequestOptions $options
     * @param array<string,mixed> $options
     *  Request-specific options, merged with/override the default options of the client. Supported options are:
     *   - 'allow_redirects' => boolean: True, if redirect responses should be resolved by the client.
     *   - 'body' => Request body as string: Optional body to send with the HTTP request
     *   - 'headers' => [ 'Headername' => 'Value' | [ 'Val1', 'Val2', ...] ]: Headers to include with the request
     *
     * @return Psr7Response The response retrieved from the server.
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface if request could not be sent or response could not be parsed
     * @throws \Psr\Http\Client\RequestExceptionInterface if request is not a well-formed HTTP request or is missing
     *   some critical piece of information (such as a Host or Method)
     * @throws \Psr\Http\Client\NetworkExceptionInterface if the request cannot be sent due to a network failure of  any
     *   kind, including a timeout
     */
    abstract public function sendRequest(string $method, string $uri, array $options = []): Psr7Response;

    /**
     * Checks whether the given URI has the same domain as the base URI of this HTTP client.
     *
     * If the given URI does not contain a domain part, true is returned (as when used, it will
     * get that part from the base URI).
     *
     * @param string $uri The URI to check
     * @return bool True if the URI shares the same domain as the base URI.
     */
    protected function checkSameDomainAsBase(string $uri): bool
    {
        $compUri = \Sabre\Uri\parse($uri);

        // if the URI is relative, the domain is the same
        if (isset($compUri["host"])) {
            $compBase = \Sabre\Uri\parse($this->baseUri);

            $result = strcasecmp(
                self::getDomainFromSubdomain($compUri["host"]),
                self::getDomainFromSubdomain($compBase["host"] ?? "")
            ) === 0;
        } else {
            $result = true;
        }

        return $result;
    }

    /**
     * Extracts the domain name from a subdomain.
     *
     * If the given string does not have a subdomain (i.e. top-level domain or domain only),
     * it is returned as provided.
     *
     * @param string $subdomain The subdomain (e.g. sub.example.com)
     * @return string The domain of $subdomain (e.g. example.com)
     */
    protected static function getDomainFromSubdomain(string $subdomain): string
    {
        $parts = explode(".", $subdomain);

        if (count($parts) > 2) {
            $subdomain = implode(".", array_slice($parts, -2));
        }

        return $subdomain;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
