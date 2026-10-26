<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
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
 * @psalm-type RequestOptions = array{
 *   allow_redirects?: bool,
 *   body?: string,
 *   headers?: array<string, string | list<string>>
 * }
 *
 * @psalm-import-type HttpOptions from Account
 *
 * @package Internal\Communication
 */
abstract class HttpClientAdapter
{
    /**
     * Defines which credential attributes are required for auth mechanisms.
     * If a mechanism is not listed, it is assumed that no credentials are mandatory (e.g. GSSAPI/Kerberos).
     */
    protected const NEEDED_AUTHNFO = [
        'basic' => [ 'username', 'password' ],
        'digest' => [ 'username', 'password' ],
        'bearer' => [ 'bearertoken' ]
    ];

    /**
     * The base URI for requests.
     * @var string
     */
    protected $baseUri;

    /**
     * The HTTP options to use for communication, including authentication credentials.
     * @var HttpOptions
     */
    protected $httpOptions;

    /** Constructs a HttpClientAdapter object.
     *
     * @param string $base_uri Base URI to be used when relative URIs are given to requests.
     * @param HttpOptions $httpOptions Credentials used to authenticate with the server.
     */
    protected function __construct(string $base_uri, array $httpOptions)
    {
        $this->baseUri = $base_uri;
        $this->httpOptions = $httpOptions;
    }

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
     * Checks whether the credentials may be sent with a request to the given URI.
     *
     * The credentials are bound to the origin (scheme, host and port) of the base URI of this HTTP client. For a
     * request to a different origin, the auth_origin_callback of the HTTP options decides if configured, otherwise the
     * credentials are not sent. Independent of the callback, credentials are never sent over plain HTTP if the base
     * URI uses HTTPS.
     *
     * @param string $uri The target URI of the request. If relative, it is taken relative to the base URI.
     * @return bool True if the credentials may be sent with the request.
     */
    public function mayAuthenticate(string $uri): bool
    {
        $targetUri = \Sabre\Uri\resolve($this->baseUri, $uri);

        if (self::isSameOrigin($targetUri, $this->baseUri)) {
            return true;
        }

        $targetScheme = strtolower(\Sabre\Uri\parse($targetUri)['scheme'] ?? '');
        $baseScheme = strtolower(\Sabre\Uri\parse($this->baseUri)['scheme'] ?? '');
        if ($baseScheme === 'https' && $targetScheme !== 'https') {
            Config::$logger->debug("Not sending credentials to $targetUri: no HTTPS");
            return false;
        }

        $callback = $this->httpOptions['auth_origin_callback'] ?? null;
        if (isset($callback) && $callback($targetUri, $this->baseUri)) {
            Config::$logger->debug("Sending credentials to $targetUri as permitted by auth_origin_callback");
            return true;
        }

        Config::$logger->debug("Not sending credentials to $targetUri: different origin than {$this->baseUri}");
        return false;
    }

    /**
     * Checks whether two absolute URIs have the same origin, i.e. the same scheme, host and port.
     *
     * The comparison is case-insensitive and considers the default port of the scheme if the port is not given. IP
     * addresses are compared by their binary value, so different notations of the same IPv6 address and the
     * IPv4-mapped IPv6 notation of an IPv4 address are considered the same host.
     *
     * @return bool True if both URIs have the same origin. False if not, or if any of the URIs lacks scheme or host.
     */
    public static function isSameOrigin(string $uri1, string $uri2): bool
    {
        $origin1 = self::getOrigin($uri1);
        return isset($origin1) && $origin1 === self::getOrigin($uri2);
    }

    /**
     * Provides a normalized string representation of the origin of an absolute URI.
     *
     * The result is a URL of the form scheme://host:port, with scheme and host in lowercase. If the URI does not
     * contain a port, the default port of the scheme is used for http and https; for other schemes, the port is
     * omitted. IP addresses are converted to their canonical notation, with IPv4-mapped IPv6 addresses converted to the
     * IPv4 address.
     *
     * @return ?string The normalized origin, null if the URI lacks scheme or host.
     */
    private static function getOrigin(string $uri): ?string
    {
        $comp = \Sabre\Uri\parse($uri);
        $scheme = strtolower($comp['scheme'] ?? '');
        $host = strtolower($comp['host'] ?? '');

        if ($scheme === '' || $host === '') {
            return null;
        }

        $ip = inet_pton(trim($host, '[]'));
        if ($ip !== false) {
            // IPv4-mapped IPv6 address (::ffff:a.b.c.d) is the same host as the IPv4 address a.b.c.d
            if (strlen($ip) === 16 && strncmp($ip, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
                $ip = substr($ip, 12);
            }
            $ipText = inet_ntop($ip);
            if ($ipText !== false) {
                $host = (strlen($ip) === 4) ? $ipText : "[$ipText]";
            }
        }

        $port = $comp['port'] ?? [ 'http' => 80, 'https' => 443 ][$scheme] ?? null;
        return isset($port) ? "$scheme://$host:$port" : "$scheme://$host";
    }

    /**
     * Checks if the needed credentials for an authentication scheme are available.
     *
     * @param lowercase-string $scheme The authentication scheme to check for
     * @return bool True if the credentials needed for the scheme are available.
     */
    protected function checkCredentialsAvailable(string $scheme): bool
    {
        if (isset(self::NEEDED_AUTHNFO[$scheme])) {
            foreach (self::NEEDED_AUTHNFO[$scheme] as $c) {
                if (!isset($this->httpOptions[$c])) {
                    return false;
                }
            }
        }

        return true;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
