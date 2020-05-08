<?php

require_once 'DAVAdapterGuzzle.php';

/*
Other needed features:
  - Setting extra headers (Depth, Content-Type, charset, If-Match, If-None-Match)
  - Debug output HTTP traffic to logfile
*/
abstract class DAVAdapter
{
	const NSDAV     = 'DAV:';
	const NSCARDDAV = 'urn:ietf:params:xml:ns:carddav';

	protected $base_uri;

	protected function __construct($base_uri)
	{
		$this->base_uri = $base_uri;
	}

	// factory method
	public static function createAdapter($base_uri, $username, $password, $options=array())
	{
		$dav = new DAVAdapterGuzzle($base_uri, $username, $password, $options);
		return $dav;
	}

	abstract public function request($method, $uri, $options=array());

	/**
	 * Queries the given URI for the current-user-principal property.
	 *
	 * @param string $contextPathUri
	 *  The given URI should typically be a context path per the terminology of RFC6764.
	 *
	 * @return
	 *  The principal URI (string), or false in case of error. The returned URI is suited
	 *  to be used for queries with this DAVAdapter object (i.e. either a full URI,
	 *  or meaningful as relative URI to the base URI of this DAVAdapter).
	 */
	public function findCurrentUserPrincipal(string $contextPathUri)
	{
		$result = $this->findProperties($contextPathUri, 'DAV:current-user-principal');
		$xml = $result["xml"];

		$princUrlAbsolute = false;
		if ($xml !== false)
		{
			$princurl = $xml->xpath('//DAV:current-user-principal/DAV:href');
			if (is_array($princurl) && count($princurl) > 0)
			{
				$princUrlAbsolute = self::absoluteUrl($result['location'], (string) $princurl[0]);
				echo "principal URL: $princUrlAbsolute\n";
			}
		}

		return $princUrlAbsolute;
	}

	/**
	 * Queries the given URI for the current-user-principal property.
	 *
	 * @param string $principalUri
	 *  The given URI should be (one of) the authenticated user's principal URI(s).
	 *
	 * @return
	 *  The user's addressbook home URI (string), or false in case of error. The returned URI is suited
	 *  to be used for queries with this DAVAdapter object (i.e. either a full URI,
	 *  or meaningful as relative URI to the base URI of this DAVAdapter).
	 */
	public function findAddressbookHome(string $principalUri)
	{
		$result = $this->findProperties($principalUri, 'CARDDAV:addressbook-home-set');
		$xml = $result["xml"];

		$addressbookHomeUriAbsolute = false;
		if ($xml !== false)
		{
			$abookhome = $xml->xpath('//CARDDAV:addressbook-home-set/DAV:href');
			if (is_array($abookhome) && count($abookhome) > 0)
			{
				$addressbookHomeUriAbsolute = self::absoluteUrl($result['location'], (string) $abookhome[0]);
				echo "addressbook home: $addressbookHomeUriAbsolute\n";
			}
		}

		return $addressbookHomeUriAbsolute;
	}

	public function findAddressbooks($addressbookHomeUri)
	{
		$result = $this->findProperties($addressbookHomeUri, [ 'DAV:resourcetype', 'DAV:displayname' ], "1");
		$xml = $result["xml"];

		$abooksResult = array();
		if ($xml !== false)
		{
			// select the responses that have a successful (status 200) resourcetype addressbook response
			$abooks = $xml->xpath("//DAV:response[DAV:propstat[contains(DAV:status,' 200 ')]/DAV:prop/DAV:resourcetype/CARDDAV:addressbook]");
			if (is_array($abooks))
			{
				foreach($abooks as $abook)
				{
					self::registerNamespaces($abook);
					$abookUri = $abook->xpath('child::DAV:href');
					if (is_array($abookUri) && count($abookUri) > 0)
					{
						$abookUri = (string) $abookUri[0];

						$abookName = $abook->xpath("child::DAV:propstat[contains(DAV:status,' 200 ')]/DAV:prop/DAV:displayname");
						if (is_array($abookName) && count($abookName) > 0)
						{
							$abookName = (string) $abookName[0];
						}
						else
						{
							$abookName = basename($abookUri);
							echo "Autosetting name from $abookUri to $abookName\n";
						}

						$abookUri = self::absoluteUrl($result['location'], $abookUri);
						echo "Found addressbook at $abookUri named $abookName\n";
						$abooksResult[] = [ "name" => $abookName, "uri" => $abookUri ];
					}
				}
			}
		}

		return $abooksResult;
	}

	// $props is either a single property or an array of properties
	// Namespace shortcuts: DAV for DAV, CARDDAV for the CardDAV namespace
	// Common properties:
	// DAV:current-user-principal
	// DAV:resourcetype
	// DAV:displayname
	// CARDDAV:addressbook-home-set
	private function findProperties($uri, $props, $depth="0")
	{
		if (!is_array($props))
		{
			$props = array($props);
		}
		$body  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
		$body .= '<DAV:propfind xmlns:DAV="DAV:" xmlns:CARDDAV="urn:ietf:params:xml:ns:carddav"><DAV:prop>' . "\n";
		foreach ($props as $prop)
		{
			$body .= "<" . $prop . "/>\n";
		}
		$body .= '</DAV:prop></DAV:propfind>';

		$result = $this->requestWithRedirectionTarget('PROPFIND', $uri, ["headers" => ["Depth"=>$depth], "body" => $body]);
		$result["xml"] = self::checkAndParseXML($result["response"]);
		return $result;
	}

	// XML helpers
	public static function checkAndParseXML($davReply)
	{
		$xml = false;
		$status = $davReply->getStatusCode();
		if( (($status >= 200) && ($status < 300)) &&
			preg_match(';(?i)(text|application)/xml;', $davReply->getHeaderLine('Content-Type')) )
		{
			$xml = self::parseXML($davReply->getBody());
		}

		return $xml;
	}

	protected static function parseXML(string $xmlString)
	{
		try
		{
			$xml = new SimpleXMLElement($xmlString);
			self::registerNamespaces($xml);
		}
		catch (Exception $e)
		{
			echo "XML could not be parsed: " . $e->getMessage() . "\n";
			$xml = false;
		}

		return $xml;
	}

	protected static function registerNamespaces($xml)
	{
		$xml->registerXPathNamespace('CARDDAV', self::NSCARDDAV);
		$xml->registerXPathNamespace('DAV', self::NSDAV);
	}

	protected function requestWithRedirectionTarget($method, $uri, $options = [])
	{
		$options['allow_redirects'] = false;

		$redirAttempt = 0;
		$redirLimit = 5;

		$uri = self::absoluteUrl($this->base_uri, $uri);

		do
		{
			$response = $this->request($method, $uri, $options);
			$scode = $response->getStatusCode();

			// 301	Moved Permanently
			// 308	Permanent Redirect
			// 302	Found
			// 307	Temporary Redirect
			$isRedirect = (($scode==301) || ($scode==302) || ($scode==307) || ($scode==308));

			if($isRedirect && $response->hasHeader('Location'))
			{
				$uri = self::absoluteUrl($uri, $response->getHeaderLine['Location']);
				$redirAttempt++;
			}
			else
			{
				break;
			}

		} while ($redirAttempt < $retryLimit);

		return
		[
			'redirected' => ($redirAttempt == 0),
			'location' => $uri,
			'response' => $response
		];
	}

	protected static function absoluteUrl(string $baseurl, string $relurl)
	{
		$basecomp = parse_url($baseurl);
		$targetcomp = parse_url($relurl);

		foreach (["scheme", "host", "port"] as $k)
		{
			if(!array_key_exists($k, $targetcomp))
			{
				$targetcomp[$k] = $basecomp[$k];
			}
		}

		$targeturl = $targetcomp["scheme"] . "://" . $targetcomp["host"];
		if (array_key_exists("port", $basecomp))
		{
			$targeturl .= ":" . $targetcomp["port"];
		}
		$targeturl .= $targetcomp["path"];

		return $targeturl;
	}
}

?>
