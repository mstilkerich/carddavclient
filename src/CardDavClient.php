<?php

/**
 * Class CardDavClient
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use SimpleXMLElement;
use Psr\Http\Message\ResponseInterface as Psr7Response;

/*
Other needed features:
  - Setting extra headers (Depth, Content-Type, charset, If-Match, If-None-Match)
  - Debug output HTTP traffic to logfile
 */
class CardDavClient
{
    /********* CONSTANTS *********/
    public const NSDAV     = 'DAV:';
    public const NSCARDDAV = 'urn:ietf:params:xml:ns:carddav';

    protected const DAV_PROPERTIES = [
        'current-user-principal' => [
            'friendlyname' => 'Principal URI',
            'ns'           => 'DAV:',
            'converter'    => array('self', 'extractAbsoluteHref')
        ],
        'addressbook-home-set' => [
            'friendlyname' => 'Addressbooks Home URI',
            'ns'           => 'CARDDAV:',
            'converter'    => array('self', 'extractAbsoluteHref')
        ],
        'displayname' => [
            'friendlyname' => 'Collection Name',
            'ns'           => 'DAV:'
        ],
        'supported-address-data' => [
            'friendlyname' => 'Supported media types for address objects',
            'ns'           => 'CARDDAV:'
        ],
        'addressbook-description' => [
            'friendlyname' => 'Addressbook description',
            'ns'           => 'CARDDAV:'
        ],
        'max-resource-size' => [
            'friendlyname' => 'Maximum allowed size (octets) of an address object',
            'ns'           => 'CARDDAV:'
        ]
    ];

    /********* PROPERTIES *********/
    /** @var string */
    protected $base_uri;

    /** @var HttpClientAdapterInterface */
    protected $httpClient;

    /********* PUBLIC FUNCTIONS *********/
    public function __construct(
        string $base_uri,
        string $username,
        string $password,
        array $options = []
    ) {
        $this->base_uri = $base_uri;
        $this->httpClient = new HttpClientAdapterGuzzle($base_uri, $username, $password, $options);
    }

    /**
     * Queries the given URI for the current-user-principal property.
     *
     * @param string $contextPathUri
     *  The given URI should typically be a context path per the terminology of RFC6764.
     *
     * @return
     *  The principal URI (string), or NULL in case of error. The returned URI is suited
     *  to be used for queries with this client (i.e. either a full URI,
     *  or meaningful as relative URI to the base URI of this client).
     */
    public function findCurrentUserPrincipal(string $contextPathUri): ?string
    {
        $result = $this->findProperties($contextPathUri, ["DAV:current-user-principal"]);

        $princUrlAbsolute = $result[0]["props"]["DAV:current-user-principal"] ?? null;

        if (isset($princUrlAbsolute)) {
            echo "principal URL: $princUrlAbsolute\n";
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
     *  to be used for queries with this client (i.e. either a full URI,
     *  or meaningful as relative URI to the base URI of this client).
     */
    public function findAddressbookHome(string $principalUri): ?string
    {
        $result = $this->findProperties($principalUri, ["CARDDAV:addressbook-home-set"]);

        $addressbookHomeUriAbsolute = $result[0]["props"]["CARDDAV:addressbook-home-set"] ?? null;

        if (isset($addressbookHomeUriAbsolute)) {
            echo "addressbook home: $addressbookHomeUriAbsolute\n";
        }

        return $addressbookHomeUriAbsolute;
    }

    // RFC6352: An address book collection MUST report the DAV:collection and CARDDAV:addressbook XML elements in the
    // value of the DAV:resourcetype property.
    // CARDDAV:supported-address-data (supported Media Types (e.g. vCard3, vCard4) of an addressbook collectioan)
    // CARDDAV:addressbook-description (property of an addressbook collection)
    // CARDDAV:max-resource-size (maximum size in bytes for an address object of the addressbook collection)
    public function findAddressbooks(string $addressbookHomeUri): array
    {
        $abooks = $this->findProperties(
            $addressbookHomeUri,
            [
                "DAV:resourcetype",
                "DAV:displayname",
                "CARDDAV:supported-address-data",
                "CARDDAV:addressbook-description",
                "CARDDAV:max-resource-size"
            ],
            "1",
            "DAV:propstat[contains(DAV:status,' 200 ')]/DAV:prop/DAV:resourcetype/CARDDAV:addressbook"
        );

        $abooksResult = [];
        foreach ($abooks as $abook) {
            $abookUri = self::absoluteUrl($addressbookHomeUri, $abook["uri"]);

            $abooksResult[] = [
                "uri"   => $abookUri,
                "props" => $abook["props"]
            ];
        }

        return $abooksResult;
    }

    /********* PRIVATE FUNCTIONS *********/
    // $props is either a single property or an array of properties
    // Namespace shortcuts: DAV for DAV, CARDDAV for the CardDAV namespace
    // RFC4918: There is always only a single value for a property, which is an XML fragment
    private function findProperties(
        string $uri,
        array $props,
        string $depth = "0",
        string $responseXPathPredicate = "true()"
    ): array {
        $body  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $body .= '<DAV:propfind xmlns:DAV="DAV:" xmlns:CARDDAV="urn:ietf:params:xml:ns:carddav"><DAV:prop>' . "\n";
        foreach ($props as $prop) {
            $body .= "<" . $prop . "/>\n";
        }
        $body .= '</DAV:prop></DAV:propfind>';

        $result = $this->requestWithRedirectionTarget(
            'PROPFIND',
            $uri,
            [
                "headers" =>
                [
                    // RFC4918: A client MUST submit a Depth header with a value of "0", "1", or "infinity"
                    "Depth" => $depth,
                    // Prefer: reduce reply size if supported, see RFC8144
                    "Prefer" => "return=minimal"
                ],
                "body" => $body
            ]
        );

        $xml = self::checkAndParseXML($result["response"]);

        $resultProperties = [];
        // extract retrieved properties
        if (isset($xml)) {
            $okResponses = $xml->xpath("//DAV:response[$responseXPathPredicate]") ?: [];
            foreach ($okResponses as $responseXml) {
                self::registerNamespaces($responseXml);
                $uri = $responseXml->xpath('child::DAV:href') ?: [];
                if (isset($uri[0])) {
                    $resultProperty = [ 'uri' => (string) $uri[0], 'props' => [] ];

                    $okProps = $responseXml->xpath("DAV:propstat[contains(DAV:status,' 200 ')]/DAV:prop/*") ?: [];
                    foreach ($okProps as $propXml) {
                        self::registerNamespaces($propXml);
                        $propShort = $propXml->getName();
                        $propFQ = (self::DAV_PROPERTIES[$propShort]["ns"] ?? "") . $propShort;

                        if (in_array($propFQ, $props)) {
                            if (isset(self::DAV_PROPERTIES[$propShort]['converter'])) {
                                $val = call_user_func(
                                    self::DAV_PROPERTIES[$propShort]['converter'],
                                    $propXml,
                                    $result
                                );
                            } else {
                                $val = (string) $propXml;
                            }
                            $resultProperty["props"][$propFQ] = $val;
                        }
                    }

                    $resultProperties[] = $resultProperty;
                }
            }
        }

        return $resultProperties;
    }

    // XML helpers
    private static function checkAndParseXML(Psr7Response $davReply): ?SimpleXMLElement
    {
        $xml = null;
        $status = $davReply->getStatusCode();

        if (
            (($status >= 200) && ($status < 300))
            && preg_match(';(?i)(text|application)/xml;', $davReply->getHeaderLine('Content-Type'))
        ) {
            $xml = self::parseXML((string) $davReply->getBody());
        }

        return $xml;
    }

    private static function parseXML(string $xmlString): ?SimpleXMLElement
    {
        try {
            $xml = new SimpleXMLElement($xmlString);
            self::registerNamespaces($xml);
        } catch (\Exception $e) {
            echo "XML could not be parsed: " . $e->getMessage() . "\n";
            $xml = null;
        }

        return $xml;
    }

    private static function registerNamespaces(SimpleXMLElement $xml): void
    {
        $xml->registerXPathNamespace('CARDDAV', self::NSCARDDAV);
        $xml->registerXPathNamespace('DAV', self::NSDAV);
    }

    private function requestWithRedirectionTarget(string $method, string $uri, array $options = []): array
    {
        $options['allow_redirects'] = false;

        $redirAttempt = 0;
        $redirLimit = 5;

        $uri = self::absoluteUrl($this->base_uri, $uri);

        do {
            $response = $this->httpClient->sendRequest($method, $uri, $options);
            $scode = $response->getStatusCode();

            // 301 Moved Permanently
            // 308 Permanent Redirect
            // 302 Found
            // 307 Temporary Redirect
            $isRedirect = (($scode == 301) || ($scode == 302) || ($scode == 307) || ($scode == 308));

            if ($isRedirect && $response->hasHeader('Location')) {
                $uri = self::absoluteUrl($uri, $response->getHeaderLine('Location'));
                $redirAttempt++;
            } else {
                break;
            }
        } while ($redirAttempt < $redirLimit);

        return [
            "redirected" => ($redirAttempt == 0),
            "location" => $uri,
            "response" => $response
        ];
    }

    private static function absoluteUrl(string $baseurl, string $relurl): string
    {
        $basecomp = parse_url($baseurl);
        $targetcomp = parse_url($relurl);

        foreach (["scheme", "host", "port"] as $k) {
            if (!key_exists($k, $targetcomp)) {
                $targetcomp[$k] = $basecomp[$k];
            }
        }

        $targeturl = $targetcomp["scheme"] . "://" . $targetcomp["host"];
        if (key_exists("port", $basecomp)) {
            $targeturl .= ":" . $targetcomp["port"];
        }
        $targeturl .= $targetcomp["path"];

        return $targeturl;
    }

    private static function extractAbsoluteHref(SimpleXMLElement $parentElement, array $findPropertiesResult): ?string
    {
        $hrefAbsolute = null;

        self::registerNamespaces($parentElement);
        $href = $parentElement->xpath("child::DAV:href");
        if (isset($href[0])) {
            $hrefAbsolute = self::absoluteUrl($findPropertiesResult["location"], (string) $href[0]);
        }

        return $hrefAbsolute;
    }



    // Addressbook collections only contain 1) address objects or 2) collections that are (recursively) NOT addressbooks
    // I.e. all address objects an be found directly within the addressbook collection, and no nesting of addressbooks
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
