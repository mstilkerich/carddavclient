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
 * Class Discovery
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Services;

use MStilkerich\CardDavClient\{Account, AddressbookCollection, CardDavClient, Config};

class Discovery
{
    /********* PROPERTIES *********/

    /** @var array Some builtins for public providers that don't have discovery properly set up. */
    private static $known_servers = [
        "gmail.com" => "www.googleapis.com",
        "googlemail.com" => "www.googleapis.com",
    ];

    /********* PUBLIC FUNCTIONS *********/
    public function discoverAddressbooks(Account $account): array
    {
        $uri = $account->getDiscoveryUri();
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
            $port = $force_ssl ? 443 : 80;
        }

        // (1) Discover the hostname and port (may be multiple results for failover setups)
        // $servers is array of:
        //     [host => "contacts.abc.com", port => "443", scheme => "https", dnsrr => '_carddavs._tcp.abc.com']
        // servers in the array are ordered by precedence, highest first
        // dnsrr is only set when the server was discovered by lookup of DNS SRV record
        $servers = $this->discoverServers($host, $force_ssl);

        // some builtins for providers that have discovery for the domains known to
        // users not properly set up
        if (key_exists($host, self::$known_servers)) {
            $servers[] = [ "host" => self::$known_servers[$host], "port" => $port, "scheme" => $protocol];
        }

        // as a fallback, we will last try what the user provided
        $servers[] = [ "host" => $host, "port" => $port, "scheme" => $protocol];

        $addressbooks = array();

        // (2) Discover the "initial context path" for each servers (until first success)
        foreach ($servers as $server) {
            $baseurl = $server["scheme"] . "://" . $server["host"] . ":" . $server["port"];
            $account->setUrl($baseurl);

            $contextpaths = $this->discoverContextPath($server);
            foreach ($contextpaths as $contextpath) {
                Config::$logger->debug("Try context path $contextpath");
                // (3) Attempt a PROPFIND asking for the DAV:current-user-principal property
                $principalUri = $account->findCurrentUserPrincipal($contextpath);
                if (isset($principalUri)) {
                    // (4) Attempt a PROPFIND asking for the addressbook home of the user on the principal URI
                    $addressbookHomeUri = $account->findAddressbookHome($principalUri);
                    if (isset($addressbookHomeUri)) {
                        // (5) Attempt PROPFIND (Depth 1) to discover all addressbooks of the user
                        foreach ($account->findAddressbooks($addressbookHomeUri) as $davAbookUri) {
                            $addressbooks[] = new AddressbookCollection($davAbookUri, $account);
                        }

                        if (count($addressbooks) > 0) {
                            break 2;
                        }
                    }
                }
            }
        }

        return $addressbooks;
    }

    /********* PRIVATE FUNCTIONS *********/
    private function discoverServers(string $host, bool $force_ssl): array
    {
        $servers = array();

        $rrnamesAndSchemes = [ ["_carddavs._tcp.$host", 'https'] ];
        if ($force_ssl === false) {
            $rrnamesAndSchemes[] = ["_carddav._tcp.$host", 'http'];
        }

        foreach ($rrnamesAndSchemes as $rrnameAndScheme) {
            list($rrname, $scheme) = $rrnameAndScheme;

            // query SRV records
            $dnsresults = dns_get_record($rrname, DNS_SRV);

            if (is_array($dnsresults)) {
                break;
            }
        }

        if (is_array($dnsresults)) {
            // order according to priority and weight
            // TODO weight is not quite correctly handled atm, see RFC2782,
            // but this is not crucial to functionality
            $sortPrioWeight = function (array $a, array $b): int {
                if ($a['pri'] != $b['pri']) {
                    return $b['pri'] - $a['pri'];
                }

                return $a['weight'] - $b['weight'];
            };

            usort($dnsresults, $sortPrioWeight);

            // build results
            foreach ($dnsresults as $dnsres) {
                $servers[] =
                    [
                        "host"   => $dnsres['target'],
                        "port"   => $dnsres['port'],
                        "scheme" => $scheme,
                        "dnsrr"  => $rrname
                    ];

                Config::$logger->info("Found server per DNS SRV $rrname: " . $dnsres['target'] . ":" . $dnsres['port']);
            }
        }

        return $servers;
    }

    private function discoverContextPath(array $server): array
    {
        $contextpaths = array();

        if (key_exists("dnsrr", $server)) {
            $dnsresults = dns_get_record($server["dnsrr"], DNS_TXT);
            if (is_array($dnsresults)) {
                foreach ($dnsresults as $dnsresult) {
                    if (key_exists('txt', $dnsresult) && preg_match('/^path=(.+)/', $dnsresult['txt'], $match)) {
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
