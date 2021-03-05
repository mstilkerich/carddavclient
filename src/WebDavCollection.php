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

/**
 * Represents a collection on a WebDAV server.
 *
 * @package Public\Entities
 */
class WebDavCollection extends WebDavResource
{
    /**
     * List of properties to query in refreshProperties() and returned by getProperties().
     * @psalm-var list<string>
     * @see WebDavResource::getProperties()
     * @see WebDavResource::refreshProperties()
     */
    private const PROPNAMES = [
        XmlEN::SYNCTOKEN,
        XmlEN::SUPPORTED_REPORT_SET,
        XmlEN::ADD_MEMBER
    ];

    /**
     * Returns the sync token of this collection.
     *
     * Note that the value may be cached. If this resource was just created, this is not an issue, but if a property
     * cache may exist for a longer time call {@see WebDavResource::refreshProperties()} first to ensure an up to date
     * sync token is provided.
     *
     * @return ?string The sync token, or null if the server does not provide a sync-token for this collection.
     * @api
     */
    public function getSyncToken(): ?string
    {
        $props = $this->getProperties();
        return $props[XmlEN::SYNCTOKEN] ?? null;
    }

    /**
     * Queries whether the server supports the sync-collection REPORT on this collection.
     * @return bool True if sync-collection is supported for this collection.
     * @api
     */
    public function supportsSyncCollection(): bool
    {
        return $this->supportsReport(XmlEN::REPORT_SYNCCOLL);
    }

    /**
     * Returns the child resources of this collection.
     *
     * @psalm-return list<WebDavResource>
     * @return array<int,WebDavResource> The children of this collection.
     * @api
     */
    public function getChildren(): array
    {
        $childObjs = [];

        try {
            $client = $this->getClient();
            $children = $client->findProperties($this->getUri(), [ XmlEN::RESTYPE ], "1");

            $path = $this->getUriPath();

            foreach ($children as $child) {
                $obj = parent::createInstance($child["uri"], $this->account, $child["props"][XmlEN::RESTYPE] ?? null);
                if ($obj->getUriPath() != $path) {
                    $childObjs[] = $obj;
                }
            }
        } catch (\Exception $e) {
            Config::$logger->info("Exception while querying collection children: " . $e->getMessage());
        }

        return $childObjs;
    }

    /**
     * {@inheritdoc}
     */
    protected function getNeededCollectionPropertyNames(): array
    {
        $parentPropNames = parent::getNeededCollectionPropertyNames();
        $propNames = array_merge($parentPropNames, self::PROPNAMES);
        return array_values(array_unique($propNames));
    }

    /**
     * Checks if the server supports the given REPORT on this collection.
     *
     * @param string $reportElement
     *  The XML element name of the REPORT of interest, including namespace (e.g. {DAV:}sync-collection).
     * @return bool True if the report is supported on this collection.
     */
    protected function supportsReport(string $reportElement): bool
    {
        $props = $this->getProperties();
        return in_array($reportElement, $props[XmlEN::SUPPORTED_REPORT_SET] ?? [], true);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
