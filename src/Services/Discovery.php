<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Services;

use MStilkerich\CardDavClient\{Account, AddressbookCollection, CardDavClient, Config, WebDavCollection};

/**
 * Provides a service to discover the addressbooks for a CardDAV account.
 *
 * It implements the discovery using the mechanisms specified in RFC 6764, which is based on DNS SRV/TXT records and/or
 * well-known URI redirection on the server.
 *
 * @psalm-type Server = array{
 *   host: string,
 *   port: string,
 *   scheme: string,
 *   dnsrr?: string,
 *   userinput?: bool
 * }
 *
 * @psalm-type SrvRecord = array{pri: int, weight: int, target: string, port: int}
 * @psalm-type TxtRecord = array{txt: string}
 *
 * @package Public\Services
 */
class Discovery
{
    /**
     * Some builtins for public providers that don't have discovery properly set up.
     *
     * It maps a domain name that is part of the typically used usernames to a working discovery URI. This allows
     * discovery from data as typically provided by a user without the application having to care about it.
     *
     * @var array<string,string>
     */
    private const KNOWN_SERVERS = [
        "gmail.com" => "www.googleapis.com",
        "googlemail.com" => "www.googleapis.com",
    ];

    /**
     * Discover the addressbooks for a CardDAV account.
     *
     * @param Account $account The CardDAV account providing credentials and initial discovery URI.
     * @psalm-return list<AddressbookCollection>
     * @return array<int,AddressbookCollection> The discovered addressbooks.
     *
     * @throws \Exception
     *  In case of error, sub-classes of \Exception are thrown, with an error message contained within the \Exception
     *  object.
     *
     * @api
     */
    public function discoverAddressbooks(Account $account): array
    {
        $uri = $account->getDiscoveryUri();
        Config::$logger->debug("Starting discovery with input $uri");
        if (!preg_match(';^(([^:]+)://)?(([^/:]+)(:([0-9]+))?)(/?.*)$;', $uri, $match)) {
            throw new \InvalidArgumentException("The account's discovery URI must contain a hostname (got: $uri)");
        }

        $protocol = $match[2]; // optional
        $host     = $match[4]; // mandatory
        $port     = $match[6]; // optional
        $path     = $match[7]; // optional

        // plain is only used if http was explicitly given
        $force_ssl = ($protocol !== "http");

        // setup default values if no user values given
        if (strlen($protocol) == 0) {
            $protocol = $force_ssl ? 'https' : 'http';
        }
        if (strlen($port) == 0) {
            $port = $force_ssl ? '443' : '80';
        }

        // (1) Discover the hostname and port (may be multiple results for failover setups)
        // $servers is array of:
        //     [host => "contacts.abc.com", port => "443", scheme => "https", dnsrr => '_carddavs._tcp.abc.com']
        // servers in the array are ordered by precedence, highest first
        // dnsrr is only set when the server was discovered by lookup of DNS SRV record
        $servers = $this->discoverServers($host, $force_ssl);

        // some builtins for providers that have discovery for the domains known to
        // users not properly set up
        if (key_exists($host, self::KNOWN_SERVERS)) {
            $servers[] = [ "host" => self::KNOWN_SERVERS[$host], "port" => $port, "scheme" => $protocol];
        }

        // as a fallback, we will last try what the user provided
        $servers[] = [ "host" => $host, "port" => $port, "scheme" => $protocol, "userinput" => true ];

        $addressbooks = [];

        // (2) Discover the "initial context path" for each servers (until first success)
        foreach ($servers as $server) {
            $baseurl = $server["scheme"] . "://" . $server["host"] . ":" . $server["port"];
            $account->setUrl($baseurl);

            $contextpaths = $this->discoverContextPath($server);

            // as a fallback, we will last try what the user provided
            if (($server["userinput"] ?? false) && (!empty($path))) {
                $contextpaths[] = $path;
            }

            foreach ($contextpaths as $contextpath) {
                Config::$logger->debug("Try context path $contextpath");
                // (3) Attempt a PROPFIND asking for the DAV:current-user-principal property
                $principalUri = $account->findCurrentUserPrincipal($contextpath);
                if (isset($principalUri)) {
                    // (4) Attempt a PROPFIND asking for the addressbook home of the user on the principal URI
                    $addressbookHomeUris = $account->findAddressbookHomes($principalUri);
                    try {
                        foreach ($addressbookHomeUris ?? [] as $addressbookHomeUri) {
                            // (5) Attempt PROPFIND (Depth 1) to discover all addressbooks of the user
                            $addressbookHome = new WebDavCollection($addressbookHomeUri, $account);

                            foreach ($addressbookHome->getChildren() as $abookCandidate) {
                                if ($abookCandidate instanceof AddressbookCollection) {
                                    $addressbooks[] = $abookCandidate;
                                }
                            }
                        }

                        // We found valid addressbook homes. If they contain no addressbooks, this is fine and the
                        // result of the discovery is an empty set.
                        return $addressbooks;
                    } catch (\Exception $e) {
                        Config::$logger->info("Exception while querying addressbooks: " . $e->getMessage());
                    }
                }
            }
        }

        throw new \Exception("Could not determine the addressbook home");
    }

    /**
     * Discovers the CardDAV service for the given domain using DNS SRV lookups.
     *
     * @param string $host A domain name to discover the service for
     * @param bool   $force_ssl If true, only services with transport encryption (carddavs) will be discovered,
     *                          otherwise the function will try to discover unencrypted (carddav) services after failing
     *                          to discover encrypted ones.
     * @psalm-return list<Server>
     * @return array
     *  Returns an array of associative arrays of services discovered via DNS. If nothing was found, the returned array
     *  is empty.
     */
    private function discoverServers(string $host, bool $force_ssl): array
    {
        $servers = [];

        $rrnamesAndSchemes = [ ["_carddavs._tcp.$host", 'https'] ];
        if ($force_ssl === false) {
            $rrnamesAndSchemes[] = ["_carddav._tcp.$host", 'http'];
        }

        foreach ($rrnamesAndSchemes as $rrnameAndScheme) {
            list($rrname, $scheme) = $rrnameAndScheme;

            // query SRV records
            /** @psalm-var list<SrvRecord> | false */
            $dnsresults = dns_get_record($rrname, DNS_SRV);

            if (is_array($dnsresults)) {
                break;
            }
        }

        if (is_array($dnsresults)) {
            usort($dnsresults, [self::class, 'orderDnsRecords']);

            // build results
            foreach ($dnsresults as $dnsres) {
                if (isset($dnsres['target']) && isset($dnsres['port'])) {
                    $servers[] =
                        [
                            "host"   => $dnsres['target'],
                            "port"   => (string) $dnsres['port'],
                            "scheme" => $scheme,
                            "dnsrr"  => $rrname
                        ];
                    Config::$logger->info("Found server per DNS SRV $rrname: {$dnsres['target']}: {$dnsres['port']}");
                }
            }
        }

        return $servers;
    }

    /**
     * Orders DNS records by their prio and weight.
     *
     * @psalm-param SrvRecord $a
     * @psalm-param SrvRecord $b
     *
     * @todo weight is not quite correctly handled atm, see RFC2782, but this is not crucial to functionality
     */
    private static function orderDnsRecords(array $a, array $b): int
    {
        if ($a['pri'] != $b['pri']) {
            return $b['pri'] - $a['pri'];
        }

        return $a['weight'] - $b['weight'];
    }

    /**
     * Provides a list of URIs to check for discovering the location of the CardDAV service.
     *
     * The provided context paths comprise both well-known URIs as well as paths discovered via DNS TXT records. DNS TXT
     * lookup is only performed for servers that have themselves been discovery using DNS SRV lookups, using the same
     * service resource record.
     *
     * @psalm-param Server $server
     * @param array $server A server record (associative array) as returned by discoverServers()
     * @psalm-return list<string>
     * @return string[] The context paths that should be tried for discovery in the provided order.
     * @see Discovery::discoverServers()
     */
    private function discoverContextPath(array $server): array
    {
        $contextpaths = [];

        if (isset($server["dnsrr"])) {
            /** @psalm-var list<TxtRecord> | false */
            $dnsresults = dns_get_record($server["dnsrr"], DNS_TXT);
            if (is_array($dnsresults)) {
                foreach ($dnsresults as $dnsresult) {
                    if (preg_match('/^path=(.+)/', $dnsresult['txt'] ?? "", $match)) {
                        $contextpaths[] = $match[1];
                        Config::$logger->info("Discovered context path $match[1] per DNS TXT record\n");
                    }
                }
            }
        }

        $contextpaths[] = '/.well-known/carddav';
        $contextpaths[] = '/';
        $contextpaths[] = '/co'; // workaround for iCloud

        return $contextpaths;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
