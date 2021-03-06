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
 * Represents XML DAV:multistatus elements as PHP objects (RFC 4918).
 *
 * The response child elements can be of two types, response elements containing a propstat ({@see ResponsePropstat}) or
 * reponse elements containing a status (@{see ResponseStatus}). Depending on the request, either on response type is
 * expected or a mixture of both is possible. This class has a template parameter that allows to define the specific
 * expected response type.
 *
 * From RFC 4918:
 * The ’multistatus’ root element holds zero or more ’response’ elements in any order, each with information about an
 * individual resource.
 *
 * RFC 6578 adds the sync-token child element:
 * ```xml
 * <!ELEMENT multistatus (response*, responsedescription?, sync-token?) >
 * ```
 *
 * @psalm-immutable
 * @template RT of Response
 *
 * @psalm-import-type DeserializedElem from Deserializers
 *
 * @package Internal\XmlElements
 */
class Multistatus implements \Sabre\Xml\XmlDeserializable
{
    /**
     * The optional sync-token child element of this multistatus.
     * @var ?string $synctoken
     */
    public $synctoken;

    /**
     * The reponse children of this multistatus element.
     * @psalm-var list<RT>
     * @var array<int, Response>
     */
    public $responses = [];

    /**
     * @psalm-param list<RT> $responses
     * @param array<int, Response> $responses
     * @param ?string $synctoken
     */
    public function __construct(array $responses, ?string $synctoken)
    {
        $this->responses = $responses;
        $this->synctoken = $synctoken;
    }

    /**
     * Deserializes the child elements of a DAV:multistatus element and creates a new instance of Multistatus.
     */
    public static function xmlDeserialize(\Sabre\Xml\Reader $reader): Multistatus
    {
        $responses = [];
        $synctoken = null;

        $children = $reader->parseInnerTree();
        if (is_array($children)) {
            /** @psalm-var DeserializedElem $child */
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
