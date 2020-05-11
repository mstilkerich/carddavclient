<?php

/**
 * Interface HttpClientAdapterInterface
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Psr\Http\Message\ResponseInterface as Psr7Response;

interface HttpClientAdapterInterface
{
    // Options: default options for request
    //   - headers => array('headername' => val (string) OR array(val1, val2, ...))
    //   - body => string (optional body content)
    //   TODO throw Psr\Http\Client\ClientExceptionInterface exception if request could not be sent or response could
    //   not be parsed
    //   TODO throw Psr\Http\Client\RequestExceptionInterface if request is not a well-formed HTTP request or is missing
    //   some critical piece of information (such as a Host or Method)
    //   TODO throw Psr\Http\Client\NetworkExceptionInterface if the request cannot be sent due to a network failure of
    //   any kind, including a timeout
    /**
     * Sends a HTTP request and returns a PSR-7 response.
     *
     * @param string $method
     * @param string $uri
     * @param array $options
     *
     * @return Psr7Response
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface If an error happens while processing the request.
     */
    public function sendRequest(string $method, string $uri, array $options = []): Psr7Response;
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
