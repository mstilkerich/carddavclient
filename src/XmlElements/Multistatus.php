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
 * Class to represent XML DAV:multistatus elements as PHP objects.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

class Multistatus implements \Sabre\Xml\XmlDeserializable
{
    /** @var ?string */
    public $synctoken;

    /** @var array */
    public $responses = [];

    public static function xmlDeserialize(\Sabre\Xml\Reader $reader): Multistatus
    {
        $multistatus = new self();
        $children = $reader->parseInnerTree();
        if (is_array($children)) {
            foreach ($children as $child) {
                if ($child["value"] instanceof Response) {
                    $multistatus->responses[] = $child["value"];
                } elseif ($child["name"] === XmlEN::SYNCTOKEN) {
                    $multistatus->synctoken = $child["value"];
                }
            }
        }
        return $multistatus;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
