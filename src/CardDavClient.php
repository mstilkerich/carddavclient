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
        $result = $this->findProperties($contextPathUri, ['DAV:current-user-principal']);
        $xml = $result["xml"];

        $princUrlAbsolute = null;
        if (isset($xml)) {
            $princurl = $xml->xpath('//DAV:current-user-principal/DAV:href');
            if (is_array($princurl) && count($princurl) > 0) {
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
     *  to be used for queries with this client (i.e. either a full URI,
     *  or meaningful as relative URI to the base URI of this client).
     */
    public function findAddressbookHome(string $principalUri): ?string
    {
        $result = $this->findProperties($principalUri, ['CARDDAV:addressbook-home-set']);
        $xml = $result["xml"];

        $addressbookHomeUriAbsolute = null;
        if (isset($xml)) {
            $abookhome = $xml->xpath('//CARDDAV:addressbook-home-set/DAV:href');
            if (is_array($abookhome) && count($abookhome) > 0) {
                $addressbookHomeUriAbsolute = self::absoluteUrl($result['location'], (string) $abookhome[0]);
                echo "addressbook home: $addressbookHomeUriAbsolute\n";
            }
        }

        return $addressbookHomeUriAbsolute;
    }

    public function findAddressbooks(string $addressbookHomeUri): array
    {
        $result = $this->findProperties($addressbookHomeUri, ['DAV:resourcetype', 'DAV:displayname'], "1");
        $xml = $result["xml"];

        $abooksResult = [];
        if (isset($xml)) { // select the responses that have a successful (status 200) resourcetype addressbook response
            $abooks = $xml->xpath(
                "//DAV:response[DAV:propstat[contains(DAV:status,' 200 ')]/DAV:prop/DAV:resourcetype/CARDDAV:addressbook]"
            );
            if (is_array($abooks)) {
                foreach ($abooks as $abook) {
                    self::registerNamespaces($abook);
                    $abookUri = $abook->xpath('child::DAV:href');
                    if (is_array($abookUri) && count($abookUri) > 0) {
                        $abookUri = (string) $abookUri[0];

                        $abookName = $abook->xpath(
                            "child::DAV:propstat[contains(DAV:status,' 200 ')]/DAV:prop/DAV:displayname"
                        );
                        if (is_array($abookName) && count($abookName) > 0) {
                            $abookName = (string) $abookName[0];
                        } else {
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

    /********* PRIVATE FUNCTIONS *********/
    // $props is either a single property or an array of properties
    // Namespace shortcuts: DAV for DAV, CARDDAV for the CardDAV namespace
    // Common properties:
    // DAV:current-user-principal
    // DAV:resourcetype
    // DAV:displayname
    // CARDDAV:addressbook-home-set
    private function findProperties(string $uri, array $props, string $depth = "0"): array
    {
        $body  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $body .= '<DAV:propfind xmlns:DAV="DAV:" xmlns:CARDDAV="urn:ietf:params:xml:ns:carddav"><DAV:prop>' . "\n";
        foreach ($props as $prop) {
            $body .= "<" . $prop . "/>\n";
        }
        $body .= '</DAV:prop></DAV:propfind>';

        $result = $this->requestWithRedirectionTarget(
            'PROPFIND',
            $uri,
            ["headers" => ["Depth" => $depth], "body" => $body]
        );
        $result["xml"] = self::checkAndParseXML($result["response"]);
        return $result;
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
            'redirected' => ($redirAttempt == 0),
            'location' => $uri,
            'response' => $response
        ];
    }

    private static function absoluteUrl(string $baseurl, string $relurl): string
    {
        $basecomp = parse_url($baseurl);
        $targetcomp = parse_url($relurl);

        foreach (["scheme", "host", "port"] as $k) {
            if (!array_key_exists($k, $targetcomp)) {
                $targetcomp[$k] = $basecomp[$k];
            }
        }

        $targeturl = $targetcomp["scheme"] . "://" . $targetcomp["host"];
        if (array_key_exists("port", $basecomp)) {
            $targeturl .= ":" . $targetcomp["port"];
        }
        $targeturl .= $targetcomp["path"];

        return $targeturl;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
