<?php

/**
 * Class CardDavClient
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use SimpleXMLElement;
use Psr\Http\Message\ResponseInterface as Psr7Response;
use MStilkerich\CardDavClient\XmlElements\Multistatus;

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
    public const NSCS      = 'http://calendarserver.org/ns/';

    private const MAP_NS2PREFIX = [
        self::NSDAV => 'DAV',
        self::NSCARDDAV => 'CARDDAV',
        self::NSCS => 'CS',
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
        'DAV:resourcetype' => [
            'friendlyname' => 'Resource types of a DAV resource',
            'converter'    => array('self', 'extractResourceType')
        ],
        'CARDDAV:supported-address-data' => [
            'friendlyname' => 'Supported media types for address objects',
            'converter'    => array('self', 'extractAddressDataTypes')
        ],
        'CARDDAV:addressbook-description' => [
            'friendlyname' => 'Addressbook description',
        ],
        'CARDDAV:max-resource-size' => [
            'friendlyname' => 'Maximum allowed size (octets) of an address object',
        ],
        'DAV:sync-token' => [
            'friendlyname' => 'Sync token as returned by sync-collection report',
        ],
        'CS:getctag' => [
            'friendlyname' => 'Identifies the state of a collection. '
            . 'Replaced by DAV:sync-token on servers supporting the sync-collection report.',
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
            Config::$logger->info("principal URL: $princUrlAbsolute");
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
            Config::$logger->info("addressbook home: $addressbookHomeUriAbsolute");
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
                "DAV:sync-token",
                "CS:getctag",
                "CARDDAV:supported-address-data",
                "CARDDAV:addressbook-description",
                "CARDDAV:max-resource-size"
            ],
            "1",
            "DAV:propstat[contains(DAV:status,' 200 ')]/DAV:prop/DAV:resourcetype/CARDDAV:addressbook"
        );

        $abooksResult = [];
        foreach ($abooks as $abook) {
            $abookUri = self::concatUrl($addressbookHomeUri, $abook["uri"]);

            $abooksResult[] = [
                "uri"   => $abookUri,
                "props" => $abook["props"]
            ];
        }

        return $abooksResult;
    }

    public function syncCollection(string $addressbookHomeUri, string $syncToken): Multistatus
    {
        $body  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $body .= "<DAV:sync-collection";
        $body .= self::xmlNamespacePrefixDefs();
        $body .= ">\n";
        $body .= "  <DAV:sync-token>$syncToken</DAV:sync-token>\n";
        $body .= "  <DAV:sync-level>1</DAV:sync-level>\n";
        $body .= "  <DAV:prop><DAV:getetag/></DAV:prop>\n";
        $body .= "</DAV:sync-collection>\n";

        $uri = $this->absoluteUrl($addressbookHomeUri);
        $response = $this->httpClient->sendRequest('REPORT', $uri, [
            "headers" =>
            [
                // RFC6578: Depth header is required to be 0 for sync-collection report
                "Depth" => 0,
                "Content-Type" => "application/xml; charset=UTF-8"
            ],
            "body" => $body
        ]);

        return self::checkAndParseXMLMultistatus($response);
    }

    private static function addRequiredVCardProperties(array $requestedVCardProps): array
    {
        $minimumProps = [ 'BEGIN', 'END', 'FN', 'VERSION', 'UID' ];
        foreach ($minimumProps as $prop) {
            if (!in_array($prop, $requestedVCardProps)) {
                $requestedVCardProps[] = $prop;
            }
        }

        return $requestedVCardProps;
    }

    public function multiGet(
        string $addressbookHomeUri,
        array $requestedUris,
        array $requestedVCardProps = []
    ): Multistatus {
        $body  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $body .= "<CARDDAV:addressbook-multiget";
        $body .= self::xmlNamespacePrefixDefs();
        $body .= ">\n";
        $body .= "  <DAV:prop>\n";
        $body .= "    <DAV:getetag/>\n";

        if (!empty($requestedVCardProps)) {
            $requestedVCardProps = self::addRequiredVCardProperties($requestedVCardProps);

            $body .= "    <CARDDAV:address-data>\n";
            foreach ($requestedVCardProps as $prop) {
                $body .= "      <CARDDAV:prop name=\"$prop\"/>\n";
            }
            $body .= "    </CARDDAV:address-data>\n";
        }
        $body .= "  </DAV:prop>\n";

        foreach ($requestedUris as $uri) {
            $body .= "  <DAV:href>$uri</DAV:href>\n";
        }

        $body .= "</CARDDAV:addressbook-multiget>\n";

        $uri = $this->absoluteUrl($addressbookHomeUri);
        $response = $this->httpClient->sendRequest('REPORT', $uri, [
            "headers" =>
            [
                // RFC6352: Depth: 0 header is required for addressbook-multiget report.
                "Depth" => 0,
                "Content-Type" => "application/xml; charset=UTF-8"
            ],
            "body" => $body
        ]);

        return self::checkAndParseXMLMultistatus($response);
    }

    /********* PRIVATE FUNCTIONS *********/
    private static function xmlNamespacePrefixDefs(): string
    {
        $header = "";
        foreach (self::MAP_NS2PREFIX as $ns => $prefix) {
            $header .= " xmlns:$prefix=\"$ns\"";
        }
        return $header;
    }

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
        $body .= '<DAV:propfind';
        $body .= self::xmlNamespacePrefixDefs();
        $body .= '><DAV:prop>' . "\n";

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
                        $propName = self::addNamespacePrefixToXmlName($propXml);

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

    private static function checkAndParseXMLMultistatus(Psr7Response $davReply): Multistatus
    {
        $multistatus = null;

        $status = $davReply->getStatusCode();
        if (($status === 207) && preg_match(';(?i)(text|application)/xml;', $davReply->getHeaderLine('Content-Type'))) {
            $service = Multistatus::getParserService();
            $multistatus = $service->expect('{DAV:}multistatus', (string) $davReply->getBody());
        }

        if (!($multistatus instanceof Multistatus)) {
            throw new \Exception('Response is not the expected Multistatus response.');
        }

        return $multistatus;
    }

    private static function parseXML(string $xmlString): ?SimpleXMLElement
    {
        try {
            $xml = new SimpleXMLElement($xmlString);
            self::registerNamespaces($xml);
        } catch (\Exception $e) {
            Config::$logger->error("Received XML could not be parsed", [ 'exception' => $e ]);
            $xml = null;
        }

        return $xml;
    }

    private static function registerNamespaces(SimpleXMLElement $xml): void
    {
        foreach (self::MAP_NS2PREFIX as $ns => $prefix) {
            $xml->registerXPathNamespace($prefix, $ns);
        }
    }

    private function requestWithRedirectionTarget(string $method, string $uri, array $options = []): array
    {
        $options['allow_redirects'] = false;

        $redirAttempt = 0;
        $redirLimit = 5;

        $uri = $this->absoluteUrl($uri);

        do {
            $response = $this->httpClient->sendRequest($method, $uri, $options);
            $scode = $response->getStatusCode();

            // 301 Moved Permanently
            // 308 Permanent Redirect
            // 302 Found
            // 307 Temporary Redirect
            $isRedirect = (($scode == 301) || ($scode == 302) || ($scode == 307) || ($scode == 308));

            if ($isRedirect && $response->hasHeader('Location')) {
                $uri = self::concatUrl($uri, $response->getHeaderLine('Location'));
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

    public function absoluteUrl(string $relurl): string
    {
        return self::concatUrl($this->base_uri, $relurl);
    }

    public static function concatUrl(string $baseurl, string $relurl): string
    {
        $targeturl = \Sabre\Uri\resolve($baseurl, $relurl);
        return \Sabre\Uri\normalize($targeturl);
    }

    public static function compareUrlPaths(string $url1, string $url2): bool
    {
        $comp1 = \Sabre\Uri\parse($url1);
        $comp2 = \Sabre\Uri\parse($url2);
        $p1 = rtrim($comp1["path"], "/");
        $p2 = rtrim($comp2["path"], "/");
        return $p1 === $p2;
    }

    private static function extractAbsoluteHref(SimpleXMLElement $parentElement, array $findPropertiesResult): ?string
    {
        $hrefAbsolute = null;

        self::registerNamespaces($parentElement);
        $href = $parentElement->xpath("child::DAV:href");
        if (isset($href[0])) {
            $hrefAbsolute = self::concatUrl($findPropertiesResult["location"], (string) $href[0]);
        }

        return $hrefAbsolute;
    }

    private static function extractResourceType(SimpleXMLElement $parentElement, array $findPropertiesResult): array
    {
        self::registerNamespaces($parentElement);
        $restypes = $parentElement->xpath("child::*") ?: [];

        $result = [];
        foreach ($restypes as $restype) {
            $result[] = self::addNamespacePrefixToXmlName($restype);
        }

        return $result;
    }

    private static function extractAddressDataTypes(SimpleXMLElement $parentElement, array $findPropertiesResult): array
    {
        self::registerNamespaces($parentElement);
        $addrdatatypes = $parentElement->xpath("child::CARDDAV:address-data-type") ?: [];

        $result = [];
        foreach ($addrdatatypes as $addrdatatype) {
            $contenttype = $addrdatatype['content-type'] ?? null;
            $contenttypeversion = $addrdatatype['version'] ?? null;
            if (isset($contenttype) && isset($contenttypeversion)) {
                $result[] = [ $contenttype, $contenttypeversion ];
            }
        }

        return $result;
    }

    private static function extractReportSet(SimpleXMLElement $parentElement, array $findPropertiesResult): array
    {
        self::registerNamespaces($parentElement);
        $reports = $parentElement->xpath("child::DAV:supported-report/DAV:report/*") ?: [];

        $result = [];
        foreach ($reports as $report) {
            $result[] = self::addNamespacePrefixToXmlName($report);
        }

        return $result;
    }

    // Addressbook collections only contain 1) address objects or 2) collections that are (recursively) NOT addressbooks
    // I.e. all address objects an be found directly within the addressbook collection, and no nesting of addressbooks

    /**
     * Returns the element name of the given element, prefixed with the proper namespace prefix used in this library.
     *
     * The names provided by the {@see SimpleXMLElement::getName()} function of @{see SimpleXMLElement} objects returns
     * the unqualified element name only. There is no way to retrieve the namespace of the element name. It is, however,
     * possible, to retrieve the namespaces used by the element using the {@see SimpleXMLElement::getNamespaces()}
     * function. This function returns an array mapping namespace prefixes to the namespace URNs. It may contain several
     * entries if the element contains attributes belonging to a different namespace. Since the prefix is not part of
     * the name returned by {@see SimpleXMLElement::getName()}, there is no way to know which of the namespaces provided
     * by {@see SimpleXMLElement::getNamespaces()} the element name belongs to.
     *
     * Currently, I know of no instance where an attribute is used with one of the XML elements we are interested in,
     * that belongs to a different namespace. So this function assumes that {@see SimpleXMLElement::getNamespaces() will
     * only return a single namespace that the element's name belongs to.
     *
     * @param SimpleXMLElement $xml The XML element whose qualified name should be returned.
     * @return string The qualified name of the given XML element (by means of adding one of the prefixes used by
     *     convention inside this library.
     */
    private static function addNamespacePrefixToXmlName(SimpleXMLElement $xml): string
    {
        $ns = array_values($xml->getNamespaces())[0];
        $name = self::MAP_NS2PREFIX[$ns] . ":" . $xml->getName();
        return $name;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
