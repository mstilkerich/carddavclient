<?php

require_once 'DAVAdapterGuzzle.php';

/*

Plugin response object for HTTP requests:

$reply =
[
	'success' => boolean,  // whether request was successful
	'status'  => integer,  // HTTP status code returned by server
	'statusmsg' => string, // Status message returned by server
	'headers' =>           // Response headers as associative array (headernames are lowercased!)
	[
		'etag' => string,
		...
	],
	'body'    =>           // raw body of the reply
];

 */


/*
Other needed features:
  - Setting extra headers (Depth, Content-Type, charset, If-Match, If-None-Match)
  - Debug output HTTP traffic to logfile
*/
abstract class DAVAdapter
{
	const NSDAV     = 'DAV:';
	const NSCARDDAV = 'urn:ietf:params:xml:ns:carddav';

	// factory method
	public static function createAdapter()
	{
		$dav = new DAVAdapterGuzzle();
		return $dav;
	}

	abstract public function init($base_uri, $username, $password, $options=array());

	abstract public function propfind($uri, $body, $options=array());

	abstract public function report($uri, $body, $options=array());

	abstract public function get($uri, $options=array());

	abstract public function delete($uri, $options=array());

	abstract public function put($uri, $body, $options=array());

	public function findCurrentUserPrincipal($uri)
	{
		$xml = $this->findProperties($uri, 'DAV:current-user-principal');
		$result = false;

		if ($xml !== false)
		{
			$princurl = $xml->xpath('//DAV:current-user-principal/DAV:href');
			if (is_array($princurl) && count($princurl) > 0)
			{
				echo "principal URL: ". $princurl[0] . "\n";
				$result = (string) $princurl[0];
			}
		}

		return $result;
	}

	public function findAddressbookHome($principalUri)
	{
		$xml = $this->findProperties($principalUri, 'CARDDAV:addressbook-home-set');
		$result = false;

		if ($xml !== false)
		{
			$abookhome = $xml->xpath('//CARDDAV:addressbook-home-set/DAV:href');
			if (is_array($abookhome) && count($abookhome) > 0)
			{
				echo "addressbook home: ". $abookhome[0] . "\n";
				$result = (string) $abookhome[0];
			}
		}

		return $result;
	}

	public function findAddressbooks($addressbookHomeUri)
	{
		$xml = $this->findProperties($addressbookHomeUri, [ 'DAV:resourcetype', 'DAV:displayname' ], "1");
		$result = array();

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

						echo "Found addressbook at $abookUri named $abookName\n";
						$result[] = [ "name" => $abookName, "uri" => $abookUri ];
					}
				}
			}
		}

		return $result;
	}
	// $props is either a single property or an array of properties
	// Namespace shortcuts: DAV for DAV, CARDDAV for the CardDAV namespace
	// Common properties:
	// DAV:current-user-principal
	// DAV:resourcetype
	// DAV:displayname
	// CARDDAV:addressbook-home-set
	public function findProperties($uri, $props, $depth="0")
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

		$reply = $this->propfind($uri, $body, ["headers" => ["Depth"=>$depth ]]);
		$xml   = self::checkAndParseXML($reply);
		return $xml;
	}

	// XML helpers
	public static function checkAndParseXML($davReply)
	{
		$xml = false;
		if($davReply["success"] &&
			self::check_contenttype($davReply['headers']['content-type'], ';(text|application)/xml;'))
		{
			$xml = self::parseXML($davReply['body']);
		}

		return $xml;
	}

	private static function parseXML($xmlString)
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

	private static function registerNamespaces($xml)
	{
		$xml->registerXPathNamespace('CARDDAV', self::NSCARDDAV);
		$xml->registerXPathNamespace('DAV', self::NSDAV);
	}

	private static function check_contenttype($ctheader, $expectedct)
	{
		$contentTypeMatches = false;

		if(!is_array($ctheader))
		{
			$ctheader = array($ctheader);
		}

		foreach($ctheader as $ct)
		{
			if(preg_match($expectedct, $ct))
			{
				$contentTypeMatches = true;
				break;
			}
		}

		return $contentTypeMatches;
	}
}

?>
