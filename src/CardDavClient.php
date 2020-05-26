<?php

/**
 * Class CardDavClient
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Psr\Http\Message\ResponseInterface as Psr7Response;
use MStilkerich\CardDavClient\XmlElements\Multistatus;
use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;
use MStilkerich\CardDavClient\XmlElements\Deserializers;

/*
Other needed features:
  - Setting extra headers (Depth, Content-Type, charset, If-Match, If-None-Match)
  - Debug output HTTP traffic to logfile
 */
class CardDavClient
{
    /********* CONSTANTS *********/
    private const MAP_NS2PREFIX = [
        XmlEN::NSDAV => 'DAV',
        XmlEN::NSCARDDAV => 'CARDDAV',
        XmlEN::NSCS => 'CS',
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
        $result = $this->findProperties($contextPathUri, [XmlEN::CURUSRPRINC]);

        $princUrl = $result[0]["props"][XmlEN::CURUSRPRINC]->href ?? null;

        if (isset($princUrl)) {
            $princUrl = self::concatUrl($result[0]["uri"], $princUrl);
            Config::$logger->info("principal URL: $princUrl");
        }

        return $princUrl;
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
        $result = $this->findProperties($principalUri, [XmlEN::ABOOK_HOME]);

        // FIXME per RFC several home locations could be returned, but we currently only use one. However, it is rather
        // unlikely that there would be several addressbook home locations.
        $addressbookHomeUri = $result[0]["props"][XmlEN::ABOOK_HOME]->href[0] ?? null;

        if (isset($addressbookHomeUri)) {
            $addressbookHomeUri = self::concatUrl($result[0]["uri"], $addressbookHomeUri);
            Config::$logger->info("addressbook home: $addressbookHomeUri");
        }

        return $addressbookHomeUri;
    }

    // RFC6352: An address book collection MUST report the DAV:collection and CARDDAV:addressbook XML elements in the
    // value of the DAV:resourcetype property.
    // CARDDAV:supported-address-data (supported Media Types (e.g. vCard3, vCard4) of an addressbook collection)
    // CARDDAV:addressbook-description (property of an addressbook collection)
    // CARDDAV:max-resource-size (maximum size in bytes for an address object of the addressbook collection)
    public function findAddressbooks(string $addressbookHomeUri): array
    {
        $abooks = $this->findProperties($addressbookHomeUri, [ XmlEN::RESTYPE ], "1");

        $abooksResult = [];
        foreach ($abooks as $abook) {
            if (in_array(XmlEN::RESTYPE_ABOOK, $abook["props"][XmlEN::RESTYPE])) {
                $abooksResult[] = $abook["uri"];
            }
        }

        return $abooksResult;
    }

    public function syncCollection(string $addressbookUri, string $syncToken): Multistatus
    {
        $srv = self::getParserService();
        $body = $srv->write(XmlEN::REPORT_SYNCCOLL, [
            XmlEN::SYNCTOKEN => $syncToken,
            XmlEN::SYNCLEVEL => "1",
            XmlEN::PROP => [ XmlEN::GETETAG => null ]
        ]);

        $response = $this->httpClient->sendRequest('REPORT', $addressbookUri, [
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

    public function getAddressObject(string $uri): array
    {
        $response = $this->httpClient->sendRequest('GET', $uri);

        if ($response->getStatusCode() !== 200) {
            throw new \Exception(
                "Address object $uri GET request was not successful ("
                . $response->getStatusCode()
                . "): "
                . $response->getReasonPhrase()
            );
        }

        // presence of this header is required per RFC6352:
        // "A response to a GET request targeted at an address object resource MUST contain an ETag response header
        // field indicating the current value of the strong entity tag of the address object resource."
        $etag = $response->getHeaderLine("ETag");
        if (empty($etag)) {
            throw new \Exception(
                "Response to address object $uri GET request does not include ETag header ("
                . $response->getStatusCode()
                . "): "
                . $response->getReasonPhrase()
            );
        }

        $body = (string) $response->getBody();
        if (empty($body)) {
            throw new \Exception(
                "Response to address object $uri GET request does not include a body ("
                . $response->getStatusCode()
                . "): "
                . $response->getReasonPhrase()
            );
        }

        return [ 'etag' => $etag, 'vcf' => $body ];
    }

    public function multiGet(
        string $addressbookUri,
        array $requestedUris,
        array $requestedVCardProps = []
    ): Multistatus {
        $srv = self::getParserService();

        $reqprops = [ XmlEN::GETETAG => null ];
        if (!empty($requestedVCardProps)) {
            $requestedVCardProps = self::addRequiredVCardProperties($requestedVCardProps);

            $reqprops[XmlEN::ADDRDATA] = array_map(
                function (string $prop): array {
                    return [
                        'name' => XmlEN::VCFPROP,
                        'attributes' => [ 'name' => $prop ]
                    ];
                },
                $requestedVCardProps
            );
        }

        $body = $srv->write(
            XmlEN::REPORT_MULTIGET,
            array_merge(
                [ [ 'name' => XmlEN::PROP, 'value' => $reqprops ] ],
                array_map(
                    function (string $uri): array {
                        return [ 'name' => XmlEN::HREF, 'value' => $uri ];
                    },
                    $requestedUris
                )
            )
        );

        $response = $this->httpClient->sendRequest('REPORT', $addressbookUri, [
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

    // $props is either a single property or an array of properties
    // Namespace shortcuts: DAV for DAV, CARDDAV for the CardDAV namespace
    // RFC4918: There is always only a single value for a property, which is an XML fragment
    public function findProperties(
        string $uri,
        array $props,
        string $depth = "0"
    ): array {
        $srv = self::getParserService();
        $body = $srv->write(XmlEN::PROPFIND, [
            XmlEN::PROP => array_fill_keys($props, null)
        ]);

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

        $multistatus = self::checkAndParseXMLMultistatus($result["response"]);

        $resultProperties = [];

        foreach ($multistatus->responses as $response) {
            // There may have been redirects involved in querying the properties, particularly during addressbook
            // discovery. They may even point to a different server than the original request URI. Return absolute URL
            // in the responses to allow the caller to know the actual location on that the properties where reported
            $respUri = self::concatUrl($result["location"], $response->href);

            if (!empty($response->propstat)) {
                foreach ($response->propstat as $propstat) {
                    if (isset($propstat->status) && stripos($propstat->status, " 200 ") !== false) {
                        $resultProperties[] = [ 'uri' => $respUri, 'props' => $propstat->prop->props ];
                    }
                }
            }
        }

        return $resultProperties;
    }

    /********* PRIVATE FUNCTIONS *********/

    private static function checkAndParseXMLMultistatus(Psr7Response $davReply): Multistatus
    {
        $multistatus = null;

        $status = $davReply->getStatusCode();
        if (($status === 207) && preg_match(';(?i)(text|application)/xml;', $davReply->getHeaderLine('Content-Type'))) {
            $service = self::getParserService();
            $multistatus = $service->expect(XmlEN::MULTISTATUS, (string) $davReply->getBody());
        }

        if (!($multistatus instanceof Multistatus)) {
            throw new \Exception("Response is not the expected Multistatus response. (Status $status)");
        }

        return $multistatus;
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

    private static function getParserService(): \Sabre\Xml\Service
    {

        $service = new \Sabre\Xml\Service();
        $service->namespaceMap = self::MAP_NS2PREFIX;
        $service->elementMap = [
            XmlEN::MULTISTATUS => XmlElements\Multistatus::class,
            XmlEN::PROP => XmlElements\Prop::class,
            XmlEN::ABOOK_HOME => XmlElements\AddressbookHomeSet::class,
            XmlEN::RESTYPE => '\Sabre\Xml\Deserializer\enum',
            XmlEN::SUPPORTED_REPORT_SET => [ Deserializers::class, 'deserializeSupportedReportSet' ],
            XmlEN::ADD_MEMBER => [ Deserializers::class, 'deserializeHrefSingle' ]
        ];

        $service->mapValueObject(XmlEN::RESPONSE, XmlElements\Response::class);
        $service->mapValueObject(XmlEN::PROPSTAT, XmlElements\Propstat::class);
        $service->mapValueObject(XmlEN::CURUSRPRINC, XmlElements\CurrentUserPrincipal::class);

        return $service;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
