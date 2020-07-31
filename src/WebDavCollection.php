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

/**
 * Objects of this class represent a collection on a WebDAV server.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

class WebDavCollection extends WebDavResource
{
    private const PROPNAMES = [
        XmlEN::SYNCTOKEN,
        XmlEN::SUPPORTED_REPORT_SET,
        XmlEN::ADD_MEMBER
    ];

    public function getSyncToken(): ?string
    {
        $props = $this->getProperties();
        return $props[XmlEN::SYNCTOKEN] ?? null;
    }

    public function supportsSyncCollection(): bool
    {
        return $this->supportsReport(XmlEN::REPORT_SYNCCOLL);
    }


    /**
     * Returns the child resources of this collection.
     *
     * @return WebDavResource[] The children of this collection.
     */
    public function getChildren(): array
    {
        $childObjs = [];

        try {
            $client = $this->getClient();
            $children = $client->findProperties($this->getUri(), [ XmlEN::RESTYPE ], "1");

            foreach ($children as $child) {
                $childObjs[] = parent::createInstance($child["uri"], $this->account, $child["props"][XmlEN::RESTYPE]);
            }
        } catch (\Exception $e) {
            Config::$logger->info("Exception while querying collection children: " . $e->getMessage());
        }

        return $childObjs;
    }

    /**
     * Provides the list of property names that should be requested upon call of refreshProperties().
     *
     * @return string[] A list of property names including namespace prefix (e. g. '{DAV:}resourcetype').
     *
     * @see self::getProperties()
     * @see self::refreshProperties()
     */
    protected function getNeededCollectionPropertyNames(): array
    {
        $parentPropNames = parent::getNeededCollectionPropertyNames();
        $propNames = array_merge($parentPropNames, self::PROPNAMES);
        return array_unique($propNames);
    }

    protected function supportsReport(string $reportElement): bool
    {
        $props = $this->getProperties();
        return in_array($reportElement, $props[XmlEN::SUPPORTED_REPORT_SET], true);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
