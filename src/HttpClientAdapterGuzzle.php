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
    /** @var string[] A list of authentication schemes that can be handled by Guzzle itself,
     *     independent on whether it works only with the Guzzle Curl HTTP handler or not.
     */
    private const GUZZLE_KNOWN_AUTHSCHEMES = [ 'basic', 'digest', 'ntlm' ];

    /********* PROPERTIES *********/

    /** @var Client The Client object of the Guzzle HTTP library. */
    private $client;

    /** @var string The username to use for authentication */
    private $username;

    /** @var string The password to use for authentication */
    private $password;

    /** @var ?string The HTTP authentication scheme to use */
    private $authScheme;

    /** @var string[] Auth-schemes tried without success */
    private $failedAuthSchemes = [];

    /** @var ?array Maps lowercase auth-schemes to their CURLAUTH_XXX constant.
     *     Only values not part of GUZZLE_KNOWN_AUTHSCHEMES are relevant here.
     */
    private static $schemeToCurlOpt;

    /********* PUBLIC FUNCTIONS *********/

    /** Constructs a HttpClientAdapterGuzzle object.
     *
     * @param string $base_uri Base URI to be used when relative URIs are given to requests.
     * @param string $username Username used to authenticate with the server.
     * @param string $password Password used to authenticate with the server.
     */
    public function __construct(string $base_uri, string $username, string $password)
    {
        $this->username = $username;
        $this->password = $password;

        if (!isset(self::$schemeToCurlOpt)) {
            if (extension_loaded("curl")) {
                self::$schemeToCurlOpt = [
                    'negotiate' => CURLAUTH_NEGOTIATE,
                ];
            } else {
                self::$schemeToCurlOpt = [];
            }
        }

        $stack = HandlerStack::create();
        $stack->push(Middleware::log(
            Config::$httplogger,
            new MessageFormatter("\"{method} {target} HTTP/{version}\" {code}\n" . MessageFormatter::DEBUG)
        ));

        $guzzleOptions = $this->prepareGuzzleOptions([]);
        $guzzleOptions['handler'] = $stack;
        $guzzleOptions['http_errors'] = false; // no exceptions on 4xx/5xx status, also required by PSR-18
        $guzzleOptions['base_uri'] = $base_uri;

        $this->client = new Client($guzzleOptions);
    }

    /**
     * Sends a PSR-7 request and returns a PSR-7 response.
     *
     * @param array  $options  Options for the HTTP client, and default request options. May include any of the options
     *               accepted by {@see HttpClientAdapterInterface::sendRequest()}.
     */
    public function sendRequest(string $method, string $uri, array $options = []): Psr7Response
    {
        $guzzleOptions = $this->prepareGuzzleOptions($options);

        try {
            $response = $this->client->request($method, $uri, $guzzleOptions);

            if ($response->getStatusCode() == 401) {
                foreach ($this->getSupportedAuthSchemes($response) as $scheme) {
                    $this->authScheme = $scheme;

                    Config::$logger->debug("Trying auth scheme $scheme");

                    $guzzleOptions = $this->prepareGuzzleOptions($options);
                    $response = $this->client->request($method, $uri, $guzzleOptions);

                    if ($response->getStatusCode() != 401) {
                        break;
                    } else {
                        $this->failedAuthSchemes[] = $scheme;
                    }
                }

                if ($response->getStatusCode() >= 400) {
                    Config::$logger->debug("None of the available auth schemes worked");
                    unset($this->authScheme);
                }
            }

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
    private function prepareGuzzleOptions(array $options): array
    {
        $guzzleOptions = [];
        $curlLoaded = extension_loaded("curl");

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

        if (isset($this->authScheme)) {
            $authScheme = $this->authScheme;
            Config::$logger->debug("Using auth scheme $authScheme");

            if (in_array($authScheme, self::GUZZLE_KNOWN_AUTHSCHEMES)) {
                $guzzleOptions['auth'] = [$this->username, $this->password, $this->authScheme];
            } elseif (isset(self::$schemeToCurlOpt[$authScheme])) { // will always be true
                if (isset($_SERVER['KRB5CCNAME'])) {
                    putenv("KRB5CCNAME=" . $_SERVER['KRB5CCNAME']);
                }
                $guzzleOptions["curl"] = [
                    CURLOPT_HTTPAUTH => self::$schemeToCurlOpt[$authScheme],
                    CURLOPT_USERNAME => $this->username,
                    CURLOPT_PASSWORD => $this->password
                ];
            }
        }

        if ($curlLoaded && (curl_version()["features"] & CURL_VERSION_HTTP2 !== 0)) {
            $guzzleOptions["version"] = 2.0; // HTTP2
        }

        return $guzzleOptions;
    }

    /**
     * Extracts HTTP authentication schemes from a WWW-Authenticate header.
     *
     * The schemes offered by the server in the WWW-Authenticate header are intersected with those supported by Guzzle /
     * curl. Schemes that havev been tried with this object without success are filtered.
     *
     * @param Psr7Response $response A status 401 response returned by the server.
     *
     * @return string[] An array of authentication schemes that can be tried.
     */
    private function getSupportedAuthSchemes(Psr7Response $response): array
    {
        $authHeaders = $response->getHeader("WWW-Authenticate");
        $schemes = [];

        foreach ($authHeaders as $authHeader) {
            $authHeader = trim($authHeader);
            foreach (preg_split("/\s*,\s*/", $authHeader) as $challenge) {
                if (preg_match("/^([^ =]+)(\s+[^=].*)?$/", $challenge, $matches)) { // filter auth-params
                    $scheme = strtolower($matches[1]);
                    if (
                        (in_array($scheme, self::GUZZLE_KNOWN_AUTHSCHEMES)
                          || isset($scheme, self::$schemeToCurlOpt[$scheme]))
                        && (! in_array($scheme, $this->failedAuthSchemes))
                    ) {
                        $schemes[] = $scheme;
                    }
                }
            }
        }

        return $schemes;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
