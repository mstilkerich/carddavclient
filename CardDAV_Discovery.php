<?php

require_once 'DAVAdapter.php';

class CardDAV_Discovery
{
	private static $known_servers =
	[
		"gmail.com" => "www.googleapis.com",
		"googlemail.com" => "www.googleapis.com",
	];

	private $davOptions = array();

	public function __construct($options = [])
	{
		if (array_key_exists("debugfile", $options))
		{
			$davOptions["debugfile"] = $options["debugfile"];
		}
	}

	public function discover_addressbooks($url, $usr, $pw)
	{
		if (!preg_match(';^(([^:]+)://)?(([^/:]+)(:([0-9]+))?)(/?.*)$;', $url, $match))
			return false;

		$protocol = $match[2]; // optional
		$host     = $match[4]; // mandatory
		$port     = $match[6]; // optional
		$path     = $match[7]; // optional

		// plain is only used if http was explicitly given
		$force_ssl = ($protocol !== "http");

		// setup default values if no user values given
		if($force_ssl) {
			$protocol = $protocol?$protocol:'https';
			$port     = $port    ?$port    :443;
		} else {
			$protocol = $protocol?$protocol:'http';
			$port     = $port    ?$port    :80;
		}

		// (1) Discover the hostname and port (may be multiple results for failover setups)
		// $servers = array( [ host => "contacts.abc.com", port => "443", scheme => "https", dnsrr => '_carddavs._tcp.abc.com' ], ... )
		// servers in the array are ordered by precedence, highest first
		// dnsrr is only set when the server was discovered by lookup of DNS SRV record
		$servers = $this->discoverServers($host, $force_ssl);

		// some builtins for providers that have discovery for the domains known to
		// users not properly set up
		if (array_key_exists($host, self::$known_servers))
		{
			$servers[] = [ "host" => self::$known_servers[$host], "port" => $port, "scheme" => $protocol];
		}

		// as a fallback, we will last try what the user provided
		$servers[] = [ "host" => $host, "port" => $port, "scheme" => $protocol];

		$addressbooks = array();

		// (2) Discover the "initial context path" for each servers (until first success)
		foreach ($servers as $server)
		{
			$baseuri = $server["scheme"] . "://" . $server["host"] . ":" . $server["port"];
			$dav = DAVAdapter::createAdapter($baseuri, $usr, $pw, $this->davOptions);

			$contextpaths = $this->discoverContextPath($dav, $server);
			foreach ($contextpaths as $contextpath)
			{
				echo "Try context path $contextpath\n";
				// (3) Attempt a PROPFIND asking for the DAV:current-user-principal property
				$principalUri = $dav->findCurrentUserPrincipal($contextpath);
				if ($principalUri !== false)
				{
					// (4) Attempt a PROPFIND asking for the addressbook home of the user on the principal URI
					$addressbookHomeUri = $dav->findAddressbookHome($principalUri);
					if ($addressbookHomeUri !== false)
					{
						// (5) Attempt PROPFIND (Depth 1) to discover all addressbooks of the user
						$addressbooks = $dav->findAddressbooks($addressbookHomeUri);

						if (count($addressbooks) > 0)
						{
							break 2;
						}
					}
				}
			}
		}

		return $addressbooks;
	}

	private function discoverServers($host, $force_ssl)
	{
		$servers = array();

		$rrnamesAndSchemes = [ ["_carddavs._tcp.$host", 'https'] ];
		if ($force_ssl === false)
		{
			$rrnamesAndSchemes[] = ["_carddav._tcp.$host", 'http'];
		}

		foreach ($rrnamesAndSchemes as $rrnameAndScheme)
		{
			list($rrname, $scheme) = $rrnameAndScheme;

			// query SRV records
			$dnsresults = dns_get_record($rrname, DNS_SRV);

			if (is_array($dnsresults))
			{
				break;
			}
		}

		if (is_array($dnsresults))
		{
			// order according to priority and weight
			// TODO weight is not quite correctly handled atm, see RFC2782,
			// but this is not crucial to functionality
			$sortPrioWeight = function($a, $b) {
				if ($a['pri'] != $b['pri']) {
					return $b['pri'] - $a['pri'];
				}

				return $a['weight'] - $b['weight'];
			};

			usort($dnsresults, $sortPrioWeight);

			// build results
			foreach($dnsresults as $dnsres)
			{
				$servers[] =
				[
					"host"   => $dnsres['target'],
					"port"   => $dnsres['port'],
					"scheme" => $scheme,
					"dnsrr"  => $rrname
				];

				echo "Found server per SRV lookup $rrname at " . $dnsres['target'] . ":" . $dnsres['port'] . "\n";
			}
		}

		return $servers;
	}

	private function discoverContextPath($dav, $server)
	{
		$contextpaths = array();

		if (array_key_exists("dnsrr", $server))
		{
			$dnsresults = dns_get_record($server["dnsrr"], DNS_TXT);
			if (is_array($dnsresults))
			{
				foreach($dnsresults as $dnsresult)
				{
					if (array_key_exists('txt', $dnsresult) && preg_match('/^path=(.+)/', $dnsresult['txt'], $match))
					{
						$contextpaths[] = $match;
						echo "Discovered context path $match per DNS TXT record\n";
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

