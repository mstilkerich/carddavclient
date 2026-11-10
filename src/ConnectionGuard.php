<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface as Psr7Request;
use Psr\Http\Message\ResponseInterface as Psr7Response;
use Psr\Http\Message\UriInterface as Psr7Uri;
use MStilkerich\CardDavClient\Exception\ForbiddenHostException;

/**
 * Lets the application decide on the hosts that requests may be sent to.
 *
 * An application that lets untrusted users choose the server to talk to (or that processes URIs provided by a server)
 * may want to restrict the hosts that can be accessed, for example to prevent access to services that are only
 * reachable from the host of the application (server-side request forgery). The target of a request may be given
 * directly, but may also be the target of a redirect or a hostname that resolves to an address not to be accessed.
 *
 * This class provides the mechanism to enforce such restrictions, the policy is provided by the application as a
 * callback (see the allow_host_callback HTTP option in {@see Account::__construct()}). This class is used as a
 * middleware of the Guzzle HTTP client and therefore checks every request, including each request resulting from a
 * redirect. For each request, the callback is given the host and port of the target and the IP addresses of the host.
 * If the callback does not permit the request, it is refused with a {@see ForbiddenHostException}. Otherwise, for
 * targets given by hostname, the HTTP client is bound to connect to the addresses passed to the callback, so that a
 * different result of a second DNS lookup cannot be used to circumvent the check (DNS rebinding).
 *
 * Limitations:
 *   - Binding the HTTP client to the checked addresses requires the curl extension. Without it, the HTTP client
 *     performs its own name resolution.
 *   - If a proxy is used, the proxy resolves the hostnames and must enforce the restrictions itself.
 *
 * @psalm-type AllowHostCallback = callable(string, int, list<string>): bool
 * @psalm-type GuzzleHandler = callable(Psr7Request, array): PromiseInterface<Psr7Response>
 *
 * @package Internal\Communication
 */
class ConnectionGuard
{
    /** @var int Time in seconds that the result of a name resolution is reused by this object */
    private const DNS_CACHE_TTL = 60;

    /**
     * The callback of the application that decides whether a request may be sent.
     * @psalm-var AllowHostCallback
     * @var callable
     */
    private $callback;

    /**
     * Results of name resolutions, indexed by hostname. Each entry holds the time of the lookup and the addresses.
     * @psalm-var array<string, array{int, list<string>}>
     * @var array
     */
    private $dnsCache = [];

    /**
     * Constructs a ConnectionGuard.
     *
     * @psalm-param AllowHostCallback $callback
     * @param callable $callback
     *  The callback that decides whether a request may be sent, see the allow_host_callback HTTP option in
     *  {@see Account::__construct()}.
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * Provides the Guzzle middleware function that checks each request passed to the HTTP handler.
     *
     * @psalm-return callable(GuzzleHandler): GuzzleHandler
     * @return callable The middleware function to push to the Guzzle HandlerStack.
     */
    public function middleware(): callable
    {
        return
            /** @psalm-param GuzzleHandler $handler */
            function (callable $handler): callable {
                return function (Psr7Request $request, array $options) use ($handler): PromiseInterface {
                    $addresses = $this->checkUri($request->getUri());

                    // Bind curl to the checked addresses, so it cannot get different ones from another DNS lookup
                    if (isset($addresses) && defined('CURLOPT_RESOLVE')) {
                        [ $host, $port ] = self::getHostAndPort($request->getUri());
                        $addresses = implode(',', $addresses);
                        /** @psalm-var array<int, mixed> $curlOpts */
                        $curlOpts = is_array($options['curl'] ?? null) ? $options['curl'] : [];
                        $curlOpts[CURLOPT_RESOLVE] = [ "$host:$port:$addresses" ];

                        // Depending on its version, curl may or may not strip a trailing dot from the hostname
                        if (substr($request->getUri()->getHost(), -1) === '.') {
                            $curlOpts[CURLOPT_RESOLVE][] = "$host.:$port:$addresses";
                        }
                        $options['curl'] = $curlOpts;
                    }

                    return $handler($request, $options);
                };
            };
    }

    /**
     * Checks whether a request to the given URI is permitted.
     *
     * @param Psr7Uri $uri The target URI of the request.
     *
     * @psalm-return ?list<string>
     * @return ?array
     *  If the host of the URI is a hostname that could be resolved, the IP addresses that it resolved to and that the
     *  connection must be limited to. Null if the connection needs no limitation.
     *
     * @throws ForbiddenHostException If the request is not permitted.
     */
    public function checkUri(Psr7Uri $uri): ?array
    {
        [ $host, $port ] = self::getHostAndPort($uri);

        if ($host === '') {
            throw new ForbiddenHostException("Access to a URI without host is not permitted");
        }

        // An IPv6 address may have a zone identifier (e.g. fe80::1%eth0, with the % percent-encoded in a URI) that
        // selects the network interface for a link-local address. It is not part of the address to check.
        $zonePos = strpos($host, '%');
        if ($zonePos === false) {
            $address = self::normalizeAddress($host);
        } else {
            $address = self::normalizeAddress(substr($host, 0, $zonePos));
            if (isset($address) && strpos($address, ':') === false) {
                $address = null; // only IPv6 addresses can have a zone identifier
            }
        }

        if (isset($address)) {
            $host = $address; // independent of the notation in the URI
            $addresses = [ $address ];
            $pinAddresses = null;
        } else {
            $addresses = $this->resolveCached($host);
            $pinAddresses = empty($addresses) ? null : $addresses;
        }

        if (!($this->callback)($host, $port, $addresses)) {
            Config::$logger->notice(
                "Request to $host port $port (" . implode(', ', $addresses) . ") not permitted by allow_host_callback"
            );
            throw new ForbiddenHostException("Access to host $host is not permitted");
        }

        return $pinAddresses;
    }

    /**
     * Resolves a hostname to its IP addresses.
     *
     * @param string $host The hostname to resolve
     * @psalm-return list<string>
     * @return array The IPv4 and IPv6 addresses of the host. Empty if the name cannot be resolved.
     */
    protected function resolveHost(string $host): array
    {
        $addresses = [];

        // IPv4 addresses via the resolver of the system, i.e. including the hosts file. This also converts IPv4
        // addresses in uncommon notations that inet_pton() does not accept (e.g. 127.1 or 0x7f000001).
        $ipv4Addresses = gethostbynamel($host);
        if ($ipv4Addresses !== false) {
            $addresses = $ipv4Addresses;
        }

        /** @psalm-var list<array{ipv6?: string}>|false $records */
        $records = @dns_get_record($host, DNS_AAAA);
        if ($records !== false) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return $addresses;
    }

    /**
     * Resolves a hostname to its IP addresses in normalized notation, reusing recent results.
     *
     * @psalm-return list<string>
     * @return array The IPv4 and IPv6 addresses of the host. Empty if the name cannot be resolved.
     */
    private function resolveCached(string $host): array
    {
        $now = time();
        if (isset($this->dnsCache[$host]) && ($now - $this->dnsCache[$host][0]) < self::DNS_CACHE_TTL) {
            return $this->dnsCache[$host][1];
        }

        $addresses = [];
        foreach ($this->resolveHost($host) as $address) {
            $address = self::normalizeAddress($address);
            if (isset($address) && !in_array($address, $addresses, true)) {
                $addresses[] = $address;
            }
        }

        $this->dnsCache[$host] = [ $now, $addresses ];
        return $addresses;
    }

    /**
     * Provides the normalized host and the port of a URI.
     *
     * @psalm-return array{string, int}
     * @return array The host in lowercase, without brackets and trailing dot, and the port (default port of the scheme
     *               if the URI does not contain a port).
     */
    private static function getHostAndPort(Psr7Uri $uri): array
    {
        $host = strtolower(trim($uri->getHost(), '[]'));
        $host = rtrim($host, '.');
        $port = $uri->getPort() ?? (strtolower($uri->getScheme()) === 'http' ? 80 : 443);
        return [ $host, $port ];
    }

    /**
     * Converts an IP address to its normalized notation.
     *
     * The same address can be written in different ways, particularly for IPv6. The normalized notation is the
     * canonical text representation of the address (e.g. `::1` for `0:0:0:0:0:0:0:1`). An IPv4-mapped IPv6 address
     * (`::ffff:a.b.c.d`) is converted to the IPv4 address, as a connection to it is a connection to the IPv4 address.
     *
     * @param string $address The text to convert
     * @return ?string The normalized address, null if the given text is not an IP address.
     */
    private static function normalizeAddress(string $address): ?string
    {
        $ip = @inet_pton($address);
        if ($ip === false) {
            return null;
        }

        if (strlen($ip) === 16 && strncmp($ip, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
            $ip = substr($ip, 12);
        }

        $address = inet_ntop($ip);
        return ($address === false) ? null : $address;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
