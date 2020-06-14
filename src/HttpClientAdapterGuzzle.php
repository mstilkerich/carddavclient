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

/**
 * Adapter for the Guzzle HTTP client library.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use GuzzleHttp\{Client, HandlerStack, Middleware, MessageFormatter};
use Psr\Http\Message\ResponseInterface as Psr7Response;
use Psr\Http\Client\ClientInterface as Psr18ClientInterface;
use MStilkerich\CardDavClient\Exception\{ClientException, NetworkException};

/**
 * Adapter for the Guzzle HTTP client library.
 */
class HttpClientAdapterGuzzle implements HttpClientAdapterInterface
{
    /********* PROPERTIES *********/

    /** @var Client The Client object of the Guzzle HTTP library. */
    private $client;

    /********* PUBLIC FUNCTIONS *********/

    /** Constructs a HttpClientAdapterGuzzle object.
     *
     * @param string $base_uri Base URI to be used when relative URIs are given to requests.
     * @param string $username Username used to authenticate with the server.
     * @param string $password Password used to authenticate with the server.
     * @param array  $options  Options for the HTTP client, and default request options. May include any of the options
     *               accepted by {@see HttpClientAdapterInterface::sendRequest()}.
     */
    public function __construct(string $base_uri, string $username, string $password, array $options = [])
    {
        $guzzleOptions = self::prepareGuzzleOptions($options);

        $stack = HandlerStack::create();
        $stack->push(Middleware::log(
            Config::$httplogger,
            new MessageFormatter("\"{method} {target} HTTP/{version}\" {code}\n" . MessageFormatter::DEBUG)
        ));
        $guzzleOptions['handler'] = $stack;

        $guzzleOptions['http_errors'] = false; // no exceptions on 4xx/5xx status, also required by PSR-18
        $guzzleOptions['base_uri'] = $base_uri;
        $guzzleOptions['auth'] = [$username, $password];
        $guzzleOptions['version'] = 2.0; // HTTP2

        $this->client = new Client($guzzleOptions);
    }

    /**
     * Sends a PSR-7 request and returns a PSR-7 response.
     */
    public function sendRequest(string $method, string $uri, array $options = []): Psr7Response
    {
        $guzzleOptions = self::prepareGuzzleOptions($options);

        try {
            $response = $this->client->request($method, $uri, $guzzleOptions);
            return $response;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // thrown in the event of a networking error or too many redirects
            throw new NetworkException($e->getMessage(), intval($e->getCode()), $e->getRequest(), $e);
        } catch (\InvalidArgumentException | \GuzzleHttp\Exception\GuzzleException $e) {
            // Anything else
            throw new ClientException($e->getMessage(), intval($e->getCode()), $e);
        }
    }

    /********* PRIVATE FUNCTIONS *********/
    private static function prepareGuzzleOptions(array $options): array
    {
        $guzzleOptions = [];

        foreach ([ "headers", "body" ] as $copyopt) {
            if (key_exists($copyopt, $options)) {
                $guzzleOptions[$copyopt] = $options[$copyopt];
            }
        }

        if (key_exists("allow_redirects", $options) && $options["allow_redirects"] === false) {
            $guzzleOptions["allow_redirects"] = false;
        } else {
            $guzzleOptions["allow_redirects"] = [
                'max'             => 5,
                'strict'          => true, // keep original method, i.e. do not perform GET on redirection target
                'referer'         => false,
                'protocols'       => ['http', 'https'],
                'track_redirects' => false
            ];
        }

        return $guzzleOptions;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
