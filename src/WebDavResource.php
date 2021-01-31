<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (C) 2020 Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of PHP-CardDavClient.
 *
 * PHP-CardDavClient is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PHP-CardDavClient is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PHP-CardDavClient.  If not, see <https://www.gnu.org/licenses/>.
 */


declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;
use MStilkerich\CardDavClient\XmlElements\Prop;

/**
 * Objects of this class represent a resource on a WebDAV server.
 *
 * @psalm-import-type PropTypes from Prop
 */
class WebDavResource implements \JsonSerializable
{
    /** @var string URI of the Collection */
    protected $uri;

    /** @var PropTypes WebDAV properties of the Resource */
    private $props = [];

    /** @var Account The CardDAV account this WebDAV resource is associated/accessible with. */
    protected $account;

    /** @var CardDavClient A CardDavClient object for the account's base URI */
    private $client;

    /** @var list<string> */
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
     * @param string $uri The target URI of the resource.
     * @param Account $account The account by which the URI shall be accessed.
     * @param ?string[] $restype Array with the DAV:resourcetype properties of the URI (if already available saves the
     *                           query)
     * @return WebDavResource An object that is an instance of the most suited subclass of WebDavResource.
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

    public function __construct(string $uri, Account $account)
    {
        $this->uri = $uri;
        $this->account = $account;

        $this->client = $account->getClient($uri);
    }

    /**
     * Returns the standard WebDAV properties for this resource.
     *
     * Retrieved from the server on first request, cached afterwards. Use refreshProperties() to force update of cached
     * properties.
     *
     * @return PropTypes
     */
    protected function getProperties(): array
    {
        if (empty($this->props)) {
            $this->refreshProperties();
        }
        return $this->props;
    }

    public function refreshProperties(): void
    {
        $propNames = $this->getNeededCollectionPropertyNames();
        $client = $this->getClient();
        $result = $client->findProperties($this->uri, $propNames);
        if (isset($result[0]["props"])) {
            $this->props = $result[0]["props"];
        } else {
            throw new \Exception("Failed to retrieve properties for collection " . $this->uri);
        }
    }

    public function jsonSerialize(): array
    {
        return [ "uri" => $this->uri ];
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getClient(): CardDavClient
    {
        return $this->client;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getUriPath(): string
    {
        $uricomp = \Sabre\Uri\parse($this->getUri());
        return $uricomp["path"] ?? "/";
    }

    public function getBasename(): string
    {
        $path = $this->getUriPath();
        /** @var ?string $basename */
        [ , $basename ] = \Sabre\Uri\split($path);
        return $basename ?? "";
    }

    /**
     * @return array{body: string}
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
     * @return list<string> A list of property names including namespace prefix (e. g. '{DAV:}resourcetype').
     *
     * @see self::getProperties()
     * @see self::refreshProperties()
     */
    protected function getNeededCollectionPropertyNames(): array
    {
        return self::PROPNAMES;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
