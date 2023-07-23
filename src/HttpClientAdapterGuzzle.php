<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use GuzzleHttp\{Client, HandlerStack, Middleware, MessageFormatter};
use Psr\Http\Message\RequestInterface as Psr7Request;
use Psr\Http\Message\ResponseInterface as Psr7Response;
use Psr\Http\Message\UriInterface as Psr7Uri;
use Psr\Http\Client\ClientInterface as Psr18ClientInterface;
use Psr\Log\NullLogger;
use MStilkerich\CardDavClient\Exception\{ClientException, NetworkException};

/**
 * Adapter for the Guzzle HTTP client library.
 *
 * @psalm-import-type HttpOptions from Account
 * @psalm-import-type RequestOptions from HttpClientAdapter
 *
 * @psalm-type GuzzleAllowRedirectCfg = array{
 *   max?: int,
 *   strict?: bool,
 *   referer?: bool,
 *   protocols?: list<string>,
 *   on_redirect?: callable(Psr7Request, Psr7Response, Psr7Uri): void,
 *   track_redirects?: bool
 * }
 *
 * @psalm-type GuzzleRequestOptions = array{
 *   headers?: array<string, string | list<string>>,
 *   query?: array<string, string>,
 *   body?: string | resource | \Psr\Http\Message\StreamInterface,
 *   allow_redirects?: bool | GuzzleAllowRedirectCfg,
 *   auth?: null | array{string, string} | array{string, string, string},
 *   curl?: array,
 *   base_uri?: string,
 *   http_errors?: bool,
 *   handler?: HandlerStack
 * }
 *
 * @package Internal\Communication
 */
class HttpClientAdapterGuzzle extends HttpClientAdapter
{
    /**
     * A list of authentication schemes that can be handled by Guzzle itself, independent on whether it works only with
     * the Guzzle Curl HTTP handler or not. Strings must be lowercase!
     *
     * @psalm-var list<lowercase-string>
     * @var array<int, string>
     */
    private const GUZZLE_KNOWN_AUTHSCHEMES = [ 'basic', 'digest', 'ntlm' ];

    /**
     * A list of authentication schemes that can be handled by this HttpClientAdapter.
     *
     * @psalm-var list<lowercase-string>
     * @var array<int, string>
     */
    private $known_authschemes;

    /**
     * The Client object of the Guzzle HTTP library.
     * @var Client
     */
    private $client;

    /**
     * The HTTP authentication scheme to use. Null if not determined yet.
     * @var ?string
     */
    private $authScheme;

    /**
     * HTTP authentication schemes tried without success, to avoid trying again.
     * @psalm-var list<lowercase-string>
     * @var array<int, string>
     */
    private $failedAuthSchemes = [];

    /**
     * Maps lowercase auth-schemes to their CURLAUTH_XXX constant. Only values not part of GUZZLE_KNOWN_AUTHSCHEMES are
     * relevant here.
     * @var null|array<lowercase-string, int>
     */
    private static $schemeToCurlOpt;

    /** Constructs a HttpClientAdapterGuzzle object.
     *
     * @param string $base_uri Base URI to be used when relative URIs are given to requests.
     * @param HttpOptions $httpOptions Options for HTTP communication, including authentication credentials.
     */
    public function __construct(string $base_uri, array $httpOptions)
    {
        parent::__construct($base_uri, $httpOptions);

        if (!isset(self::$schemeToCurlOpt)) {
            self::$schemeToCurlOpt = [];

            if (extension_loaded("curl")) {
                self::$schemeToCurlOpt['curlany'] = CURLAUTH_ANY;

                // if CURL is compiled without support for SPNEGO, CURLAUTH_NEGOTIATE is not defined
                if (defined('CURLAUTH_NEGOTIATE')) {
                    self::$schemeToCurlOpt['negotiate'] = CURLAUTH_NEGOTIATE;
                }
            }
        }

        if ($httpOptions['preemptive_basic_auth'] ?? false) {
            if ($this->checkCredentialsAvailable('basic')) {
                $this->authScheme = 'basic';
            } else {
                Config::$logger->warning("Ignoring option preemptive_basic_auth as username/password are not set");
            }
        }

        $this->known_authschemes = array_merge(
            [ 'bearer' ],
            self::GUZZLE_KNOWN_AUTHSCHEMES,
            array_keys(self::$schemeToCurlOpt)
        );

        $stack = HandlerStack::create();

        if (!(Config::$httplogger instanceof NullLogger)) {
            $stack->push(Middleware::log(
                Config::$httplogger,
                new MessageFormatter(Config::$options['guzzle_logformat'])
            ));
        }

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
                    $this->authScheme = null;
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
     * @psalm-return GuzzleRequestOptions
     * @param bool $doAuth True to attempt authentication. False will only try unauthenticated access.
     */
    private function prepareGuzzleOptions(array $options = [], bool $doAuth = false): array
    {
        $guzzleOptions = [];

        // First we need to merge multi-value options, e.g. HTTP headers. We do not attempt to merge individual
        // values though, the values in options take precedence.
        if (isset($options['headers']) && isset($this->httpOptions['headers'])) {
            $options['headers'] = $options['headers'] + $this->httpOptions['headers'];
        }

        // Now merge the request options with the default options. In case of merged options above, options will already
        // contain the result of the merge in case both arrays have an entry for it.
        $options = $options + $this->httpOptions;

        // These options are also known to Guzzle and can directly be passed along
        foreach ([ "headers", "body", "verify", "query" ] as $copyopt) {
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
                $guzzleOptions['auth'] = [
                    $this->httpOptions['username'] ?? "",
                    $this->httpOptions['password'] ?? "",
                    $authScheme
                ];
            } elseif (isset(self::$schemeToCurlOpt[$authScheme])) {
                if (isset($_SERVER['KRB5CCNAME'])) {
                    putenv("KRB5CCNAME=" . $_SERVER['KRB5CCNAME']);
                }
                $guzzleOptions["curl"] = [
                    CURLOPT_HTTPAUTH => self::$schemeToCurlOpt[$authScheme],
                    CURLOPT_USERNAME => $this->httpOptions['username'] ?? "",
                    CURLOPT_PASSWORD => $this->httpOptions['password'] ?? ""
                ];
            } else { // handled by HttpClientAdapterGuzzle directly
                if ($authScheme == "bearer" && isset($this->httpOptions['bearertoken'])) {
                    $authHeader = sprintf("%s %s", 'Bearer', $this->httpOptions['bearertoken']);
                    /** @psalm-var GuzzleRequestOptions $guzzleOptions */
                    $guzzleOptions["headers"]["Authorization"] = $authHeader;
                }
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

        /** @psalm-var GuzzleRequestOptions $guzzleOptions */
        return $guzzleOptions;
    }

    /**
     * Extracts HTTP authentication schemes from a WWW-Authenticate header.
     *
     * The schemes offered by the server in the WWW-Authenticate header are intersected with those supported by Guzzle /
     * curl. Schemes that have been tried with this object without success are filtered.
     *
     * @param Psr7Response $response A status 401 response returned by the server.
     *
     * @psalm-return list<lowercase-string>
     * @return array<int,string> An array of authentication schemes that can be tried.
     */
    private function getSupportedAuthSchemes(Psr7Response $response): array
    {
        $authHeaders = $response->getHeader("WWW-Authenticate");
        $schemes = [];
        $availableSchemes = array_diff($this->known_authschemes, $this->failedAuthSchemes);
        foreach ($authHeaders as $authHeader) {
            $authHeader = trim($authHeader);
            $srvSchemes = [];

            foreach (preg_split("/\s*,\s*/", $authHeader) as $challenge) {
                if (preg_match("/^([^ =]+)(\s+[^=].*)?$/", $challenge, $matches)) { // filter auth-params
                    $srvSchemes[] = strtolower($matches[1]);
                }
            }

            // some providers do not advertise Bearer auth in the WWW-Authenticate header, this is a hack to work around
            if (
                // Google: https://issuetracker.google.com/issues/189153568
                strstr($authHeader, 'realm="Google APIs"') !== false
                // Yahoo
                || strstr($authHeader, 'realm="progrss"') !== false
            ) {
                $srvSchemes[] = 'bearer';
            }

            foreach ($srvSchemes as $scheme) {
                if (in_array($scheme, $availableSchemes) && $this->checkCredentialsAvailable($scheme)) {
                    $schemes[] = $scheme;
                }
            }
        }

        return $schemes;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
