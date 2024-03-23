<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

/**
 * Represents an account on a CardDAV Server.
 *
 * @psalm-type HttpOptions = array{
 *   username?: string,
 *   password?: string,
 *   bearertoken?: string,
 *   verify?: bool|string,
 *   preemptive_basic_auth?: bool,
 *   query?: array<string, string>,
 *   headers?: array<string, string | list<string>>,
 * }
 *
 * @psalm-type SerializedAccount = HttpOptions & array{discoveryUri: string, baseUrl?: ?string}
 *
 * @package Public\Entities
 */
class Account implements \JsonSerializable
{
    /**
     * Options for the HTTP communication, including authentication credentials.
     * @var HttpOptions
     */
    private $httpOptions;

    /**
     * URI originally used to discover the account.
     * Example: _example.com_
     * @var string
     */
    private $discoveryUri;

    /**
     * URL of the discovered CardDAV server, with empty path. May be null if not discovered yet.
     * Example: _https://carddav.example.com:443_
     * @var ?string
     */
    private $baseUrl;

    /**
     * Construct a new Account object.
     *
     * @param string $discoveryUri
     *  The URI to use for service discovery. This can be a partial URI, in the simplest case just a domain name. Note
     *  that if no protocol is given, https will be used. Unencrypted HTTP will only be done if explicitly given (e.g.
     *  _http://example.com_).
     * @psalm-param string|HttpOptions $httpOptions
     * @param string|array $httpOptions
     *  The options for HTTP communication, including authentication credentials.
     *  An array with any of the keys (if no password is needed, the array may be empty, e.g. GSSAPI/Kerberos):
     *    - username/password: The username and password for mechanisms requiring such credentials (e.g. Basic, Digest)
     *    - bearertoken: The token to use for Bearer authentication (OAUTH2)
     *    - verify: How to verify the server-side HTTPS certificate. True (the default) enables certificate
     *                  verification against the default CA bundle of the OS, false disables certificate verification
     *                  (note that this defeats the purpose of HTTPS and opens the door for man in the middle attacks).
     *                  Set to the path of a PEM file containing a custom CA bundle to perform verification against a
     *                  custom set of certification authorities.
     *    - preemptive_basic_auth: Set to true to always submit an Authorization header for HTTP Basic authentication
     *      (username and password options also required in this case) even if not challenged by the server. This may be
     *      required in rare use cases where the server allows unauthenticated access and will not challenge the client.
     *    - query: Query options to append to every URL queried for this account, as an associative array of query
     *             options and values.
     *    - headers: Headers to add to each request sent for this account, an associative array mapping header name to
     *               header value (string) or header values (list<string>).
     *
     *  Deprecated: username as string for authentication mechanisms requiring username / password.
     * @param string $password
     *  Deprecated: The password to use for authentication. This parameter is deprecated, include the password in the
     *  $httpOptions parameter. This parameter is ignored unless $httpOptions is used in its deprecated string form.
     * @param string $baseUrl
     *  The URL of the CardDAV server without the path part (e.g. _https://carddav.example.com:443_). This URL is used
     *  as base URL for the underlying {@see CardDavClient} that can be retrieved using {@see Account::getClient()}.
     *  When relative URIs are passed to the client, they will be relative to this base URL. If this account is used for
     *  discovery with the {@see Services\Discovery} service, this parameter can be omitted.
     * @api
     */
    public function __construct(string $discoveryUri, $httpOptions, string $password = "", string $baseUrl = null)
    {
        $this->discoveryUri = $discoveryUri;
        $this->baseUrl = $baseUrl;

        if (is_string($httpOptions)) {
            $this->httpOptions = [
                'username' => $httpOptions,
                'password' => $password
            ];
        } else {
            $this->httpOptions = $httpOptions;
        }
    }

    /**
     * Constructs an Account object from an array representation.
     *
     * This can be used to reconstruct/deserialize an Account from a stored (JSON) representation.
     *
     * @psalm-param SerializedAccount $props
     * @param array<string,?string|bool> $props An associative array containing the Account attributes.
     *  Keys with the meaning from {@see Account::__construct()}:
     *  `discoveryUri`, `baseUrl`, `username`, `password`, `bearertoken`, `verify`, `preemptive_basic_auth`
     * @see Account::jsonSerialize()
     * @api
     */
    public static function constructFromArray(array $props): Account
    {
        $requiredProps = [ 'discoveryUri' ];
        foreach ($requiredProps as $prop) {
            if (!isset($props[$prop])) {
                throw new \Exception("Array used to reconstruct account does not contain required property $prop");
            }
        }

        /** @psalm-var SerializedAccount $props vimeo/psalm#10853 */
        $discoveryUri = $props["discoveryUri"];
        $baseUrl = $props["baseUrl"] ?? null;
        unset($props["discoveryUri"], $props["baseUrl"]);

        return new Account($discoveryUri, $props, "", $baseUrl);
    }

    /**
     * Allows to serialize an Account object to JSON.
     *
     * @psalm-return SerializedAccount
     * @return array<string, ?string|bool> Associative array of attributes to serialize.
     * @see Account::constructFromArray()
     */
    public function jsonSerialize(): array
    {
        return [
            "discoveryUri" => $this->discoveryUri,
            "baseUrl" => $this->baseUrl
        ] + $this->httpOptions;
    }

    /**
     * Provides a CardDavClient object to interact with the server for this account.
     *
     * @param string $baseUrl
     *  A base URL to use by the client to resolve relative URIs. If not given, the base url of the Account is used.
     *  This is useful, for example, to override the base path with that of a collection.
     *
     * @return CardDavClient
     *  A CardDavClient object to interact with the server for this account.
     */
    public function getClient(?string $baseUrl = null): CardDavClient
    {
        $clientUri = $baseUrl ?? $this->getUrl();
        return new CardDavClient($clientUri, $this->httpOptions);
    }

    /**
     * Returns the discovery URI for this Account.
     * @api
     */
    public function getDiscoveryUri(): string
    {
        return $this->discoveryUri;
    }

    /**
     * Set the base URL of this account once the service URL has been discovered.
     */
    public function setUrl(string $url): void
    {
        $this->baseUrl = $url;
    }

    /**
     * Returns the base URL of the CardDAV service.
     * @api
     */
    public function getUrl(): string
    {
        if (is_null($this->baseUrl)) {
            throw new \Exception("The base URI of the account has not been discovered yet");
        }

        return $this->baseUrl;
    }

    /**
     * Provides a readable form of the core properties of the Account.
     *
     * This is meant for printing to a human, not for parsing, and therefore may change without considering this a
     * backwards incompatible change.
     */
    public function __toString(): string
    {
        $str = $this->discoveryUri;
        $str .= ", user: " . ($this->httpOptions['username'] ?? "");
        $str .= ", CardDAV URI: ";
        $str .= $this->baseUrl ?? "not discovered yet";
        return $str;
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
     * @return ?string
     *  The principal URI (string), or NULL in case of error. The returned URI is suited to be used for queries with
     *  this client (i.e. either a full URI, or meaningful as relative URI to the base URI of this client).
     */
    public function findCurrentUserPrincipal(string $contextPathUri): ?string
    {
        $princUrl = null;

        try {
            $client = $this->getClient();
            $result = $client->findProperties($contextPathUri, [XmlEN::CURUSRPRINC]);

            if (isset($result[0]["props"][XmlEN::CURUSRPRINC])) {
                $princUrl = $result[0]["props"][XmlEN::CURUSRPRINC];
                $princUrl = CardDavClient::concatUrl($result[0]["uri"], $princUrl);
                Config::$logger->info("principal URL: $princUrl");
            }
        } catch (\Exception $e) {
            Config::$logger->info("Exception while querying current-user-principal: " . $e->getMessage());
        }

        return $princUrl;
    }

    /**
     * Queries the given URI for the CARDDAV:addressbook-home-set property.
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
     * @return null|list<string>
     *  The user's addressbook home URIs, or null in case of error. The returned URIs are suited to be used for queries
     *  with this client (i.e. either a full URI, or meaningful as relative URI to the base URI of this client).
     */
    public function findAddressbookHomes(string $principalUri): ?array
    {
        $addressbookHomeUris = [];

        try {
            $client = $this->getClient();
            $result = $client->findProperties($principalUri, [XmlEN::ABOOK_HOME]);

            if (isset($result[0]["props"][XmlEN::ABOOK_HOME])) {
                $hrefs = $result[0]["props"][XmlEN::ABOOK_HOME];

                foreach ($hrefs as $href) {
                    $addressbookHomeUri = CardDavClient::concatUrl($result[0]["uri"], $href);
                    $addressbookHomeUris[] = $addressbookHomeUri;
                    Config::$logger->info("addressbook home: $addressbookHomeUri");
                }
            }
        } catch (\Exception $e) {
            Config::$logger->info("Exception while querying addressbook-home-set: " . $e->getMessage());
            return null;
        }

        return $addressbookHomeUris;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
