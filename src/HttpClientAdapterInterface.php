<?php

/**
 * Interface for the internal HTTP client adapter.
 *
 * This interface intends to decouple the rest of this library from the underlying HTTP client library to allow for
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
 * @author Michael Stilkerich <michael@stilkerich.eu>
 * @copyright 2020 Michael Stilkerich
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License, version 2 (or later)
 * @internal
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Psr\Http\Message\ResponseInterface as Psr7Response;

/**
 * Interface for the internal HTTP client adapter.
 */
interface HttpClientAdapterInterface
{
    /**
     * Sends an HTTP request and returns a PSR-7 response.
     *
     * @param string $method The request method (GET, PROPFIND, etc.)
     * @param string $uri The target URI. If relative, taken relative to the internal base URI of the HTTP client
     * @param array $options Request-specific options, merged with/override the default options of the HTTP client.
     *        Supported options are:
     *          'allow_redirects' => boolean: True, if redirect responses should be resolved by the client.
     *          'body' => Request body as string: Optional body to send with the HTTP request
     *          'headers' => [ 'Headername' => 'Value' | [ 'Val1', 'Val2', ...] ]: Headers to include with the request
     *
     * @return Psr7Response The response retrieved from the server.
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface if request could not be sent or response could not be parsed
     * @throws \Psr\Http\Client\RequestExceptionInterface if request is not a well-formed HTTP request or is missing
     *   some critical piece of information (such as a Host or Method)
     * @throws \Psr\Http\Client\NetworkExceptionInterface if the request cannot be sent due to a network failure of  any
     *   kind, including a timeout
     */
    public function sendRequest(string $method, string $uri, array $options = []): Psr7Response;
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
