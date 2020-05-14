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

    private const MAP_NS2PREFIX = [
        self::NSDAV => 'DAV',
        self::NSCARDDAV => 'CARDDAV'
    ];

    public const DAV_PROPERTIES = [
        'DAV:current-user-principal' => [
            'friendlyname' => 'Principal URI',
            'converter'    => array('self', 'extractAbsoluteHref')
        ],
        'CARDDAV:addressbook-home-set' => [
            'friendlyname' => 'Addressbooks Home URI',
            'converter'    => array('self', 'extractAbsoluteHref')
        ],
        'DAV:displayname' => [
            'friendlyname' => 'Collection Name',
        ],
        'DAV:supported-report-set' => [
            'friendlyname' => 'Supported Reports',
            'converter'    => array('self', 'extractReportSet')
        ],
        'CARDDAV:supported-address-data' => [
            'friendlyname' => 'Supported media types for address objects',
        ],
        'CARDDAV:addressbook-description' => [
            'friendlyname' => 'Addressbook description',
        ],
        'CARDDAV:max-resource-size' => [
            'friendlyname' => 'Maximum allowed size (octets) of an address object',
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
     * Property description by RFC5397: The DAV:current-user-principal property contains either a DAV:href or
     * DAV:unauthenticated XML element. The DAV:href element contains a URL to a principal resource corresponding to the
     * currently authenticated user. That URL MUST be one of the URLs in the DAV:principal-URL or DAV:alternate-URI-set
     * properties defined on the principal resource and MUST be an http(s) scheme URL. When authentication has not been
     * done or has failed, this property MUST contain the DAV:unauthenticated pseudo-principal.
     * In some cases, there may be multiple principal resources corresponding to the same authenticated principal. In
     * that case, the server is free to choose any one of the principal resource URIs for the value of the
     * DAV:current-user-principal property. However, servers SHOULD be consistent and use the same principal resource
     * URI for each authenticated principal.
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
     * Property description by RFC6352: The CARDDAV:addressbook-home-set property is meant to allow users to easily find
     * the address book collections owned by the principal. Typically, users will group all the address book collections
     * that they own under a common collection. This property specifies the URL of collections that are either address
     * book collections or ordinary collections that have child or descendant address book collections owned by the
     * principal.
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
    // CARDDAV:supported-address-data (supported Media Types (e.g. vCard3, vCard4) of an addressbook collection)
    // CARDDAV:addressbook-description (property of an addressbook collection)
    // CARDDAV:max-resource-size (maximum size in bytes for an address object of the addressbook collection)
    public function findAddressbooks(string $addressbookHomeUri): array
    {
        $abooks = $this->findProperties(
            $addressbookHomeUri,
            [
                "DAV:resourcetype",
                "DAV:displayname",
                "DAV:supported-report-set",
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
                        $propNs = array_values($propXml->getNamespaces())[0];
                        $propName = self::MAP_NS2PREFIX[$propNs] . ":" . $propXml->getName();

                        if (in_array($propName, $props)) {
                            if (isset(self::DAV_PROPERTIES[$propName]['converter'])) {
                                $val = call_user_func(
                                    self::DAV_PROPERTIES[$propName]['converter'],
                                    $propXml,
                                    $result
                                );
                            } else {
                                $val = (string) $propXml;
                            }
                            $resultProperty["props"][$propName] = $val;
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
        $xml->registerXPathNamespace(self::MAP_NS2PREFIX[self::NSCARDDAV], self::NSCARDDAV);
        $xml->registerXPathNamespace(self::MAP_NS2PREFIX[self::NSDAV], self::NSDAV);
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

    private static function extractReportSet(SimpleXMLElement $parentElement, array $findPropertiesResult): array
    {
        self::registerNamespaces($parentElement);
        $reports = $parentElement->xpath("child::DAV:supported-report/DAV:report/*") ?: [];

        $result = [];
        foreach ($reports as $report) {
            self::registerNamespaces($report);
            $result[] = $report->getName();
        }

        return $result;
    }



    // Addressbook collections only contain 1) address objects or 2) collections that are (recursively) NOT addressbooks
    // I.e. all address objects an be found directly within the addressbook collection, and no nesting of addressbooks
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120