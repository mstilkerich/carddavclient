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

namespace MStilkerich\CardDavClient\XmlElements;

use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;
use MStilkerich\CardDavClient\Exception\XmlParseException;

/**
 * Class to represent XML DAV:multistatus elements as PHP objects. (RFC 4918)
 *
 * From RFC 4918:
 *
 * The ’multistatus’ root element holds zero or more ’response’ elements in any order, each with information about an
 * individual resource.
 *
 * RFC 6578 adds the sync-token child element:
 * <!ELEMENT multistatus (response*, responsedescription?, sync-token?) >
 *
 * @psalm-immutable
 * @template RT of Response
 *
 * @psalm-import-type DeserializedElem from Deserializers
 */
class Multistatus implements \Sabre\Xml\XmlDeserializable
{
    /** @var ?string $synctoken */
    public $synctoken;

    /** @var RT[] $responses */
    public $responses = [];

    /**
     * @param RT[] $responses
     * @param ?string $synctoken
     */
    public function __construct(array $responses, ?string $synctoken)
    {
        $this->responses = $responses;
        $this->synctoken = $synctoken;
    }

    public static function xmlDeserialize(\Sabre\Xml\Reader $reader): Multistatus
    {
        $responses = [];
        $synctoken = null;

        $children = $reader->parseInnerTree();
        if (is_array($children)) {
            /** @var DeserializedElem $child */
            foreach ($children as $child) {
                if ($child["value"] instanceof Response) {
                    $responses[] = $child["value"];
                } elseif ($child["name"] === XmlEN::SYNCTOKEN) {
                    if (is_string($child["value"])) {
                        $synctoken = $child["value"];
                    }
                }
            }
        }

        return new self($responses, $synctoken);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
