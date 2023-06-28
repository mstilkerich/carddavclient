<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Psr\Http\Message\ResponseInterface as Psr7Response;
use MStilkerich\CardDavClient\XmlElements\Prop;
use MStilkerich\CardDavClient\XmlElements\Filter;
use MStilkerich\CardDavClient\XmlElements\Multistatus;
use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;
use MStilkerich\CardDavClient\XmlElements\Deserializers;
use MStilkerich\CardDavClient\Exception\XmlParseException;

/**
 * Implements the operations of the CardDAV protocol.
 *
 * This class implements the lower level interactions with the CardDAV server that are utilized by the higher-level
 * operations offered by the public entities ({@see AddressbookCollection} etc.) and services ({@see Services\Sync},
 * {@see Services\Discovery}.
 *
 * An application interacting with the carddavclient library should not interact with this class directly, and it is
 * considered an internal part of the library whose interfaces may change without being considered a change of the
 * library's API.
 *
 * @psalm-import-type HttpOptions from Account
 * @psalm-import-type RequestOptions from HttpClientAdapter
 * @psalm-import-type PropTypes from Prop
 * @package Internal\Communication
 */
class CardDavClient
{
    private const MAP_NS2PREFIX = [
        XmlEN::NSDAV => 'DAV',
        XmlEN::NSCARDDAV => 'CARDDAV',
        XmlEN::NSCS => 'CS',
    ];

    /** @var string */
    protected $base_uri;

    /** @var HttpClientAdapter */
    protected $httpClient;

    /**
     * @psalm-param HttpOptions $httpOptions
     */
    public function __construct(string $base_uri, array $httpOptions)
    {
        $this->base_uri = $base_uri;
        $this->httpClient = new HttpClientAdapterGuzzle($base_uri, $httpOptions);
    }

    /**
     * Requests a sync-collection REPORT from the CardDAV server.
     *
     * Note: Google's server does not accept an empty syncToken, though explicitly allowed for initial sync by RFC6578.
     * It will respond with 400 Bad Request and error message "Request contains an invalid argument."
     *
     * The Google issues have been reported to Google: https://issuetracker.google.com/issues/160190530
     */
    public function syncCollection(string $addressbookUri, string $syncToken): Multistatus
    {
        $srv = self::getParserService();
        $body = $srv->write(XmlEN::REPORT_SYNCCOLL, [
            XmlEN::SYNCTOKEN => $syncToken,
            XmlEN::SYNCLEVEL => "1",
            XmlEN::PROP => [ XmlEN::GETETAG => null ]
        ]);

        // RFC6578: Depth: 0 header is required for sync-collection report
        // Google requires a Depth: 1 header or the REPORT will only target the collection itself
        // This hack seems to be the simplest solution to behave RFC-compliant in general but have Google work
        // nonetheless
        if (strpos(self::concatUrl($this->base_uri, $addressbookUri), "www.googleapis.com") !== false) {
            $depthValue = "1";
        } else {
            $depthValue = "0";
        }

        $response = $this->httpClient->sendRequest('REPORT', $addressbookUri, [
            "headers" =>
            [
                "Depth" => $depthValue,
                "Content-Type" => "application/xml; charset=UTF-8"
            ],
            "body" => $body
        ]);

        return self::checkAndParseXMLMultistatus($response);
    }

    public function getResource(string $uri): Psr7Response
    {
        $response = $this->httpClient->sendRequest('GET', $uri);
        self::assertHttpStatus($response, 200, 200, "GET $uri");

        $body = (string) $response->getBody();
        if (empty($body)) {
            throw new \Exception("Response to GET $uri request does not include a body");
        }

        return $response;
    }

    /**
     * Fetches an address object.
     *
     * @param string $uri URI of the address object to fetch
     * @psalm-return array{etag: string, vcf: string}
     * @return array<string,string>
     *  Associative array with keys
     *   - etag (string): Entity tag of the created resource if returned by server, otherwise empty string.
     *   - vcf (string): The address data of the address object
     */
    public function getAddressObject(string $uri): array
    {
        $response = $this->getResource($uri);

        // presence of this header is required per RFC6352:
        // "A response to a GET request targeted at an address object resource MUST contain an ETag response header
        // field indicating the current value of the strong entity tag of the address object resource."
        $etag = $response->getHeaderLine("ETag");
        if (empty($etag)) {
            throw new \Exception("Response to address object $uri GET request does not include ETag header");
        }

        $body = (string) $response->getBody(); // checked to be present in getResource()
        return [ 'etag' => $etag, 'vcf' => $body ];
    }

    /**
     * Requests the server to delete the given resource.
     */
    public function deleteResource(string $uri): void
    {
        $response = $this->httpClient->sendRequest('DELETE', $uri);
        self::assertHttpStatus($response, 200, 204, "DELETE $uri");
    }

    /**
     * Requests the server to update the given resource.
     *
     * Normally, the ETag of the existing expected server-side resource should be given to make the update
     * conditional on that no other changes have been done to the server-side resource, otherwise lost updates might
     * occur. However, if no ETag is given, the server-side resource is overwritten unconditionally.
     *
     * @return ?string
     *  ETag of the updated resource, an empty string if no ETag was given by the server, or null if the update failed
     *  because the server-side ETag did not match the given one.
     */
    public function updateResource(string $body, string $uri, string $etag = ""): ?string
    {
        $headers = [ "Content-Type" => "text/vcard" ];
        if (!empty($etag)) {
            $headers["If-Match"] = $etag;
        }

        $response = $this->httpClient->sendRequest(
            'PUT',
            $uri,
            [
                "headers" => $headers,
                "body" => $body
            ]
        );

        $status = $response->getStatusCode();

        if ($status == 412) {
            $etag = null;
        } else {
            self::assertHttpStatus($response, 200, 204, "PUT $uri");
            $etag = $response->getHeaderLine("ETag");
        }

        return $etag;
    }

    /**
     * Requests the server to create the given resource.
     *
     * On success, the actual URI of the new resource is contained in the returned array.
     *
     * @param string $body
     *   The content of the newly created resource.
     *
     * @param string $suggestedUri
     *   - If $post=false: The suggested new URI for the resource to create. If a resource by that name
     *     already exists, names will be derived from this URI by appending a numerical suffix for a limited number of
     *     retries.
     *   - If $post=true: The "Add-Member" URI to perform the POST request to. The server chooses the URI of the new
     *     resource.
     *
     * @param bool $post
     *   If true, use a POST instead of a PUT request to create the resource (RFC 5995).
     *
     * @psalm-return array{uri: string, etag: string}
     * @return array<string,string>
     *  Associative array with keys
     *   - uri (string): URI of the new resource if the request was successful
     *   - etag (string): Entity tag of the created resource if returned by server, otherwise empty string.
     */
    public function createResource(string $body, string $suggestedUri, bool $post = false): array
    {
        $uri = $suggestedUri;
        $attempt = 0;

        $headers = [ "Content-Type" => "text/vcard" ];
        if ($post) {
            $reqtype = 'POST';
            $retryLimit = 1;
        } else {
            $reqtype = 'PUT';
            // for PUT, we have to guess a free URI, so we give it several tries
            $retryLimit = 5;
            $headers["If-None-Match"] = "*";
        }

        do {
            ++$attempt;
            $response = $this->httpClient->sendRequest(
                $reqtype,
                $uri,
                [ "headers" => $headers, "body" => $body ]
            );

            $status = $response->getStatusCode();
            // 201 -> New resource created
            // 200/204 -> Existing resource modified (should not happen b/c of If-None-Match
            // 412 -> Precondition failed
            if ($status == 412) {
                // make up a new random filename until retry limit is hit (append a random integer to the suggested
                // filename, e.g. /newcard.vcf could become /newcard-1234.vcf)
                $randint = rand();
                $uri = preg_replace("/(\.[^.]*)?$/", "-$randint$0", $suggestedUri, 1);
            }
        } while (($status == 412) && ($attempt < $retryLimit));

        self::assertHttpStatus($response, 201, 201, "$reqtype $suggestedUri");

        $etag = $response->getHeaderLine("ETag");
        if ($post) {
            $uri = $response->getHeaderLine("Location");
        }
        return [ 'uri' => $uri, 'etag' => $etag ];
    }

    /**
     * Issues an addressbook-multiget request to the server.
     *
     * @param string $addressbookUri URI of the addressbook to fetch the objects from
     * @psalm-param list<string> $requestedUris
     * @param array<int,string> $requestedUris
     *  List of URIs of the objects to fetch
     * @psalm-param list<string> $requestedVCardProps
     * @param array<int,string> $requestedVCardProps
     *  List of VCard properties to request, empty to request the full cards.
     *
     * @psalm-return Multistatus<XmlElements\ResponsePropstat>
     */
    public function multiGet(
        string $addressbookUri,
        array $requestedUris,
        array $requestedVCardProps = []
    ): Multistatus {
        $srv = self::getParserService();

        // Determine the prop element for the report
        $reqprops = [
            XmlEN::GETETAG => null,
            XmlEN::ADDRDATA => $this->determineReqCardProps($requestedVCardProps)
        ];

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
                "Depth" => "0",
                "Content-Type" => "application/xml; charset=UTF-8"
            ],
            "body" => $body
        ]);

        return self::checkAndParseXMLMultistatus($response, XmlElements\ResponsePropstat::class);
    }

    /**
     * Issues an addressbook-query report.
     *
     * @param string $addressbookUri The URI of the addressbook collection to query
     * @param Filter $filter The query filter conditions
     * @psalm-param list<string> $requestedVCardProps
     * @param array<int,string> $requestedVCardProps
     *  A list of the requested VCard properties. If empty array, the full VCards are requested from the server.
     * @param int $limit Tell the server to return at most $limit results. 0 means no limit.
     * @psalm-return Multistatus
     */
    public function query(
        string $addressbookUri,
        Filter $filter,
        array $requestedVCardProps,
        int $limit
    ): Multistatus {
        $srv = self::getParserService();

        $reportOptions = [
            // requested properties (both WebDAV and VCard properties)
            [
                'name' => XmlEN::PROP,
                'value' => [
                    XmlEN::GETETAG => null,
                    XmlEN::ADDRDATA => $this->determineReqCardProps($requestedVCardProps)
                ]
            ],
            // filter element with the conditions that cards need to match
            [
                'name' => XmlEN::FILTER,
                'attributes' => $filter->xmlAttributes(),
                'value' => $filter
            ]
        ];

        // Limit element if needed
        if ($limit > 0) {
            $reportOptions[] = [ 'name' => XmlEN::LIMIT, 'value' => [ 'name' => XmlEN::NRESULTS, 'value' => $limit ] ];
        }

        $body = $srv->write(XmlEN::REPORT_QUERY, $reportOptions);

        $response = $this->httpClient->sendRequest('REPORT', $addressbookUri, [
            "headers" =>
            [
                // RFC6352: Depth: 1 header sets query scope to the addressbook collection
                "Depth" => "1",
                "Content-Type" => "application/xml; charset=UTF-8"
            ],
            "body" => $body
        ]);

        return self::checkAndParseXMLMultistatus($response);
    }

    /**
     * Builds a CARDDAV::address-data element with the requested properties.
     *
     * If no properties are requested, returns null - an empty address-data element means that the full VCards shall be
     * returned.
     *
     * Some properties that are mandatory are added to the list.
     *
     * @psalm-param list<string> $requestedVCardProps
     * @param array<int,string> $requestedVCardProps List of the VCard properties requested by the user
     * @psalm-return null|list<array{name: string, attributes: array{name: string}}>
     * @return null|array<int, array<string, mixed>>
     */
    private function determineReqCardProps(array $requestedVCardProps): ?array
    {
        if (empty($requestedVCardProps)) {
            return null;
        }

        $requestedVCardProps = self::addRequiredVCardProperties($requestedVCardProps);

        $reqprops = array_map(
            function (string $prop): array {
                return [
                    'name' => XmlEN::VCFPROP,
                    'attributes' => [ 'name' => $prop ]
                ];
            },
            $requestedVCardProps
        );

        return $reqprops;
    }

    /**
     * Retrieves a set of WebDAV properties for a resource.
     *
     * @param string $uri The URI of the resource to retrieve properties for.
     * @psalm-param list<string> $props
     * @param array<int,string> $props
     *  List of properties to retrieve, given as XML element names
     * @psalm-param "0"|"1"|"infinity" $depth
     * @param string $depth Value for the Depth header
     *
     * @psalm-return list<array{uri: string, props: PropTypes}>
     * @return array<int, array<string,mixed>>
     */
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
                    "Content-Type" => "application/xml; charset=UTF-8",
                    // Prefer: reduce reply size if supported, see RFC8144
                    "Prefer" => "return=minimal"
                ],
                "body" => $body
            ]
        );

        $multistatus = self::checkAndParseXMLMultistatus($result["response"], XmlElements\ResponsePropstat::class);

        $resultProperties = [];

        foreach ($multistatus->responses as $response) {
            $href = $response->href;

            // There may have been redirects involved in querying the properties, particularly during addressbook
            // discovery. They may even point to a different server than the original request URI. Return absolute URL
            // in the responses to allow the caller to know the actual location on that the properties where reported
            $respUri = self::concatUrl($result["location"], $href);

            if (!empty($response->propstat)) {
                foreach ($response->propstat as $propstat) {
                    if (stripos($propstat->status, " 200 ") !== false) {
                        $resultProperties[] = [ 'uri' => $respUri, 'props' => $propstat->prop->props ];
                    }
                }
            }
        }

        return $resultProperties;
    }

    /**
     * Adds required VCard properties to a set specified by the user.
     *
     * This is needed to ensure retrieval of a valid VCard, as some properties are mandatory.
     *
     * @psalm-param list<string> $requestedVCardProps
     * @param array<int,string> $requestedVCardProps List of properties requested by the user
     * @psalm-return list<string>
     * @return array<int,string> List of properties requested by the user, completed with mandatory properties.
     */
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

    private static function assertHttpStatus(Psr7Response $davReply, int $minCode, int $maxCode, string $nfo): void
    {
        $status = $davReply->getStatusCode();

        if (($status < $minCode) || ($status > $maxCode)) {
            $reason = $davReply->getReasonPhrase();
            $body = (string) $davReply->getBody();

            throw new \Exception("$nfo HTTP request was not successful ($status $reason): $body");
        }
    }

    /**
     * @template RT of XmlElements\Response
     * @psalm-param class-string<RT> $responseType
     * @psalm-return Multistatus<RT>
     * @return Multistatus
     */
    private static function checkAndParseXMLMultistatus(
        Psr7Response $davReply,
        string $responseType = XmlElements\Response::class
    ): Multistatus {
        $multistatus = null;

        self::assertHttpStatus($davReply, 207, 207, "Expected Multistatus");
        if (preg_match(';(?i)(text|application)/xml;', $davReply->getHeaderLine('Content-Type'))) {
            $service = self::getParserService();
            $multistatus = $service->expect(XmlEN::MULTISTATUS, (string) $davReply->getBody());
        }

        if (!($multistatus instanceof Multistatus)) {
            throw new XmlParseException("Response is not the expected Multistatus response.");
        }

        foreach ($multistatus->responses as $response) {
            if (!($response instanceof $responseType)) {
                throw new XmlParseException("Multistatus contains unexpected responses (Expected: $responseType)");
            }
        }

        /** @psalm-var Multistatus<RT> */
        return $multistatus;
    }

    /**
     * Performs a WebDAV request, automatically following redirections and providing the final target with the result.
     *
     * @param string $method The WebDAV method of the request (PROPFIND, REPORT, etc.)
     * @param string $uri The target of the request
     * @param RequestOptions $options Additional options for the request
     *
     * @psalm-return array{redirected: bool, location: string, response: Psr7Response}
     * @return array<string, mixed>
     */
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
        return \Sabre\Uri\resolve($baseurl, $relurl);
    }

    public static function compareUrlPaths(string $url1, string $url2): bool
    {
        $comp1 = \Sabre\Uri\parse($url1);
        $comp2 = \Sabre\Uri\parse($url2);
        $p1 = trim(rtrim($comp1["path"] ?? '', "/"), "/");
        $p2 = trim(rtrim($comp2["path"] ?? '', "/"), "/");
        return $p1 === $p2;
    }

    private static function getParserService(): \Sabre\Xml\Service
    {

        $service = new \Sabre\Xml\Service();
        $service->namespaceMap = self::MAP_NS2PREFIX;
        $service->elementMap = array_merge(
            Prop::PROP_DESERIALIZERS,
            [
                XmlEN::MULTISTATUS => XmlElements\Multistatus::class,
                XmlEN::RESPONSE => XmlElements\Response::class,
                XmlEN::PROPSTAT => XmlElements\Propstat::class,
                XmlEN::PROP => XmlElements\Prop::class,
            ]
        );

        return $service;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
