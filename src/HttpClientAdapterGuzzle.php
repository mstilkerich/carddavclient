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

use GuzzleHttp\{Client, HandlerStack, Middleware, MessageFormatter};
use Psr\Http\Message\ResponseInterface as Psr7Response;
use Psr\Http\Client\ClientInterface as Psr18ClientInterface;
use MStilkerich\CardDavClient\Exception\{ClientException, NetworkException};

/**
 * Adapter for the Guzzle HTTP client library.
 *
 * @psalm-import-type RequestOptions from HttpClientAdapter
 *
 * @package Internal\Communication
 */
class HttpClientAdapterGuzzle extends HttpClientAdapter
{
    /**
     * A list of authentication schemes that can be handled by Guzzle itself, independent on whether it works only with
     * the Guzzle Curl HTTP handler or not.
     *
     * @psalm-var list<string>
     * @var array<int, string>
     */
    private const GUZZLE_KNOWN_AUTHSCHEMES = [ 'basic', 'digest', 'ntlm' ];

    /**
     * The Client object of the Guzzle HTTP library.
     * @var Client
     */
    private $client;

    /**
     * The username to use for authentication
     * @var string
     */
    private $username;

    /**
     * The password to use for authentication
     * @var string
     */
    private $password;

    /**
     * The HTTP authentication scheme to use. Null if not determined yet.
     * @var ?string
     */
    private $authScheme;

    /**
     * HTTP authentication schemes tried without success, to avoid trying again.
     * @psalm-var list<string>
     * @var array<int, string>
     */
    private $failedAuthSchemes = [];

    /**
     * Maps lowercase auth-schemes to their CURLAUTH_XXX constant. Only values not part of GUZZLE_KNOWN_AUTHSCHEMES are
     * relevant here.
     * @var null|array<string, int>
     */
    private static $schemeToCurlOpt;

    /** Constructs a HttpClientAdapterGuzzle object.
     *
     * @param string $base_uri Base URI to be used when relative URIs are given to requests.
     * @param string $username Username used to authenticate with the server.
     * @param string $password Password used to authenticate with the server.
     */
    public function __construct(string $base_uri, string $username, string $password)
    {
        $this->baseUri = $base_uri;
        $this->username = $username;
        $this->password = $password;

        if (!isset(self::$schemeToCurlOpt)) {
            if (extension_loaded("curl")) {
                self::$schemeToCurlOpt = [
                    'negotiate' => CURLAUTH_NEGOTIATE,
                    'curlany' => CURLAUTH_ANY,
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

        $guzzleOptions = $this->prepareGuzzleOptions();
        $guzzleOptions['handler'] = $stack;
        $guzzleOptions['http_errors'] = false; // no exceptions on 4xx/5xx status, also required by PSR-18
        $guzzleOptions['base_uri'] = $base_uri;

        $this->client = new Client($guzzleOptions);
    }

    /**
     * Sends a PSR-7 request and returns a PSR-7 response.
     *
     * The given URI may be relative to the base URI given on construction of this object or a full URL.
     * Authentication is only attempted in case the domain name of the request URI matches that of the base URI
     * (subdomains may differ).
     *
     * @psalm-param RequestOptions $options
     * @param array<string,mixed> $options
     *  Options for the HTTP client, and default request options. May include any of the options accepted by
     *  {@see HttpClientAdapter::sendRequest()}.
     */
    public function sendRequest(string $method, string $uri, array $options = []): Psr7Response
    {
        $doAuth = $this->checkSameDomainAsBase($uri);
        $guzzleOptions = $this->prepareGuzzleOptions($options, $doAuth);

        try {
            $response = $this->client->request($method, $uri, $guzzleOptions);

            // Workaround for Sabre/DAV vs. Curl incompatibility
            if ($doAuth && $this->checkSabreCurlIncompatibility($method, $response)) {
                Config::$logger->debug("Attempting workaround for Sabre/Dav / curl incompatibility");
                $guzzleOptions = $this->prepareGuzzleOptions($options, $doAuth);
                $response = $this->client->request($method, $uri, $guzzleOptions);
            }

            if ($doAuth && $response->getStatusCode() == 401) {
                foreach ($this->getSupportedAuthSchemes($response) as $scheme) {
                    $this->authScheme = $scheme;

                    Config::$logger->debug("Trying auth scheme $scheme");

                    $guzzleOptions = $this->prepareGuzzleOptions($options, $doAuth);
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

    /**
     * Checks if a request was rejected because of an incompatibility between curl and sabre/dav.
     *
     * Background: When using DIGEST authentication, it is required to first send a request to the server to determine
     * the parameters for the DIGEST authentication. This request is supposed to fail with 401 and the client can
     * determine the parameters from the WWW-Authenticate header and try again with the proper Authentication header.
     * Curl optimizes the first request by omitting the request body as it expects the request to fail anyway.
     *
     * Now sabre/dav has a feature that allows to reply to certain REPORT requests without the need for authentication.
     * This is specifically useful for Caldav, which may want to make available certain information from a calendar to
     * anonymous users (e.g. free/busy time). Therefore, the authentication is done at a later time than the first
     * attempt to evaluate the REPORT. A REPORT request requires a body, and thus sabre/dav will bail out with an
     * internal server error instead of a 401, normally causing the client library to fail. The problem specifically
     * only occurs for REPORT requests, for other requests such as PROPFIND the problem is not triggered in sabre and an
     * expected 401 response is returned.
     *
     * Read all about it {@link https://github.com/sabre-io/dav/issues/932 here}.
     *
     * As a sidenote, nextcloud is not affected even though it uses sabre/dav, because the feature causing the server
     * errors can be disabled and is in nextcloud. But there are other servers (Baïkal) using sabre/dav that are
     * affected.
     *
     * As a workaround, it is possible to ask curl to do negotiation of the authentication scheme to use, but providing
     * the authentication scheme CURLAUTH_ANY. With this, curl will not assume that the initial request might fail (as
     * not authentication may be needed), and thus the initial request will include the request body. The downside of
     * this is that even when we know the authentication scheme supported by a server (e.g. basic), this setting will
     * cause twice the number of requests being sent to the server.
     *
     * Because it doesn't seem that this issue will get fixed, and the widespread usage of sabre/dav, I decided to
     * include this workaround in the carddavclient library that specifically detects the situation and applies the
     * above workaround without affecting the efficiency of communication when talking to other servers.
     *
     * We detect the situation by the following indicators:
     *  - We have the curl extension loaded
     *  - REPORT request was sent
     *  - Result status code is 500
     *  - The server is a sabre/dav server (X-Sabre-Version header is set)
     *  - The response includes the known error message:
     *    ```xml
     *    <?xml version="1.0" encoding="utf-8"?>
     *    <d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
     *      <s:sabredav-version>4.1.2</s:sabredav-version>
     *      <s:exception>Sabre\Xml\ParseException</s:exception>
     *      <s:message>The input element to parse is empty. Do not attempt to parse</s:message>
     *    </d:error>
     *    ```
     */
    private function checkSabreCurlIncompatibility(string $method, Psr7Response $response): bool
    {
        if (
            extension_loaded("curl")
            && $response->getStatusCode() == 500
            && strcasecmp($method, "REPORT") == 0
            && $response->hasHeader("X-Sabre-Version")
        ) {
            $body = (string) $response->getBody();
            if (strpos($body, "The input element to parse is empty. Do not attempt to parse") !== false) {
                $this->authScheme = "curlany";
                return true;
            }
        }

        return false;
    }

    /**
     * Prepares options for the Guzzle request.
     *
     * @psalm-param RequestOptions $options
     * @param array<string,mixed> $options
     * @param bool $doAuth True to attempt authentication. False will only try unauthenticated access.
     */
    private function prepareGuzzleOptions(array $options = [], bool $doAuth = false): array
    {
        $guzzleOptions = [];

        foreach ([ "headers", "body" ] as $copyopt) {
            if (isset($options[$copyopt])) {
                $guzzleOptions[$copyopt] = $options[$copyopt];
            }
        }

        if (($options["allow_redirects"] ?? true) === false) {
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

        $authScheme = $this->authScheme;
        if ($doAuth && isset($authScheme)) {
            Config::$logger->debug("Using auth scheme $authScheme");

            if (in_array($authScheme, self::GUZZLE_KNOWN_AUTHSCHEMES)) {
                $guzzleOptions['auth'] = [$this->username, $this->password, $this->authScheme];
            } elseif (isset(self::$schemeToCurlOpt[$authScheme])) { // will always be true
                if (isset($_SERVER['KRB5CCNAME']) && is_string($_SERVER['KRB5CCNAME'])) {
                    putenv("KRB5CCNAME=" . $_SERVER['KRB5CCNAME']);
                }
                $guzzleOptions["curl"] = [
                    CURLOPT_HTTPAUTH => self::$schemeToCurlOpt[$authScheme],
                    CURLOPT_USERNAME => $this->username,
                    CURLOPT_PASSWORD => $this->password
                ];
            }
        }

        // On occasion, we get CURL error 16 Error in the HTTP2 framing layer
        // Until the source of this is clear, disable HTTP2 for now
        /*
        $curlLoaded = extension_loaded("curl");
        if ($curlLoaded && (curl_version()["features"] & CURL_VERSION_HTTP2 !== 0)) {
            $guzzleOptions["version"] = 2.0; // HTTP2
        }
        */

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
     * @psalm-return list<string>
     * @return array<int,string> An array of authentication schemes that can be tried.
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
                        (in_array($scheme, self::GUZZLE_KNOWN_AUTHSCHEMES) || isset(self::$schemeToCurlOpt[$scheme]))
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
