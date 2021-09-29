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
use MStilkerich\CardDavClient\XmlElements\Prop;

/**
 * Represents a resource on a WebDAV server.
 *
 * @psalm-import-type PropTypes from Prop
 *
 * @package Public\Entities
 */
class WebDavResource implements \JsonSerializable
{
    /**
     * URI of the resource
     * @var string
     */
    protected $uri;

    /**
     * Cached WebDAV properties of the resource
     * @psalm-var PropTypes
     * @var array<string, mixed>
     */
    private $props = [];

    /**
     * The CardDAV account this resource is associated/accessible with
     * @var Account
     */
    protected $account;

    /**
     * CardDavClient object for the account's base URI
     * @var CardDavClient
     */
    private $client;

    /**
     * List of properties to query in refreshProperties() and returned by getProperties().
     * @psalm-var list<string>
     */
    private const PROPNAMES = [
        XmlEN::RESTYPE,
    ];

    /**
     * Factory for WebDavResource objects.
     *
     * Given an account and URI, it attempts to create an instance of the most specific subclass matching the
     * resource identified by the given URI. In case no resource can be accessed via the given account and
     * URI, an exception is thrown.
     *
     * Compared to direct construction of the object, creation via this factory involves querying the resourcetype of
     * the URI with the server, so this is a checked form of instantiation whereas no server communication occurs when
     * using the constructor.
     *
     * @param string $uri
     *  The target URI of the resource.
     * @param Account $account
     *  The account by which the URI shall be accessed.
     * @psalm-param null|list<string> $restype
     * @param null|array<int,string> $restype
     *  Array with the DAV:resourcetype properties of the URI (if already available saves the query)
     * @return WebDavResource An object that is an instance of the most suited subclass of WebDavResource.
     * @api
     */
    public static function createInstance(string $uri, Account $account, ?array $restype = null): WebDavResource
    {
        if (!isset($restype)) {
            $res = new self($uri, $account);
            $props = $res->getProperties();
            $restype = $props[XmlEN::RESTYPE] ?? [];
        }

        if (in_array(XmlEN::RESTYPE_ABOOK, $restype)) {
            return new AddressbookCollection($uri, $account);
        } elseif (in_array(XmlEN::RESTYPE_COLL, $restype)) {
            return new WebDavCollection($uri, $account);
        } else {
            return new self($uri, $account);
        }
    }

    /**
     * Constructs a WebDavResource object.
     *
     * @param string $uri
     *  The target URI of the resource.
     * @param Account $account
     *  The account by which the URI shall be accessed.
     * @api
     */
    public function __construct(string $uri, Account $account)
    {
        $this->uri = $uri;
        $this->account = $account;

        $this->client = $account->getClient($uri);
    }

    /**
     * Returns the standard WebDAV properties for this resource.
     *
     * Retrieved from the server on first request, cached afterwards. Use {@see WebDavResource::refreshProperties()} to
     * force update of cached properties.
     *
     * @psalm-return PropTypes
     * @return array<string, mixed>
     *  Array mapping property name to corresponding value(s). The value type depends on the property.
     */
    protected function getProperties(): array
    {
        if (empty($this->props)) {
            $this->refreshProperties();
        }
        return $this->props;
    }

    /**
     * Forces a refresh of the cached standard WebDAV properties for this resource.
     *
     * @see WebDavResource::getProperties()
     * @api
     */
    public function refreshProperties(): void
    {
        $propNames = $this->getNeededCollectionPropertyNames();
        $client = $this->getClient();
        $result = $client->findProperties($this->uri, $propNames);
        if (isset($result[0]["props"])) {
            $this->props = $result[0]["props"];
        } else {
            throw new \Exception("Failed to retrieve properties for resource " . $this->uri);
        }
    }

    /**
     * Allows to serialize WebDavResource object to JSON.
     *
     * @return array<string, string> Associative array of attributes to serialize.
     */
    public function jsonSerialize(): array
    {
        return [ "uri" => $this->uri ];
    }

    /**
     * Returns the Account this resource belongs to.
     * @api
     */
    public function getAccount(): Account
    {
        return $this->account;
    }

    /**
     * Provides a CardDavClient object to interact with the server for this resource.
     *
     * The base URL used by the returned client is the URL of this resource.
     *
     * @return CardDavClient
     *  A CardDavClient object to interact with the server for this resource.
     */
    public function getClient(): CardDavClient
    {
        return $this->client;
    }

    /**
     * Returns the URI of this resource.
     * @api
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Returns the path component of the URI of this resource.
     * @api
     */
    public function getUriPath(): string
    {
        $uricomp = \Sabre\Uri\parse($this->getUri());
        return $uricomp["path"] ?? "/";
    }

    /**
     * Returns the basename (last path component) of the URI of this resource.
     * @api
     */
    public function getBasename(): string
    {
        $path = $this->getUriPath();
        /** @var ?string $basename */
        [ , $basename ] = \Sabre\Uri\split($path);
        return $basename ?? "";
    }

    /**
     * Downloads the content of a given resource.
     *
     * @param string $uri
     *  URI of the requested resource.
     *
     * @psalm-return array{body: string}
     * @return array<string,string>
     *  An associative array where the key 'body' maps to the content of the requested resource.
     * @api
     */
    public function downloadResource(string $uri): array
    {
        $client = $this->getClient();
        $response = $client->getResource($uri);
        $body = (string) $response->getBody(); // checked to be present in CardDavClient::getResource()
        return [ 'body' => $body ];
    }

    /**
     * Provides the list of property names that should be requested upon call of refreshProperties().
     *
     * @psalm-return list<string>
     * @return array<int,string> A list of property names including namespace prefix (e. g. '{DAV:}resourcetype').
     *
     * @see WebDavResource::getProperties()
     * @see WebDavResource::refreshProperties()
     */
    protected function getNeededCollectionPropertyNames(): array
    {
        return self::PROPNAMES;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
