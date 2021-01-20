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
 * Class to represent XML urn:ietf:params:xml:ns:carddav:param-filter elements as PHP objects. (RFC 6352)
 *
 * From RFC 6352
 *
 * The CARDDAV:param-filter XML element specifies search criteria on a specific vCard property parameter (e.g., TYPE) in
 * the scope of a given CARDDAV:prop-filter. A vCard property is said to match a CARDDAV:param-filter if:
 *   - A parameter of the type specified by the "name" attribute exists, and the CARDDAV:param-filter is empty, or it
 *     matches the CARDDAV:text-match conditions if specified.
 *   or:
 *   - A parameter of the type specified by the "name" attribute does not exist, and the CARDDAV:is-not-defined element
 *     is specified.
 *
 * <!ELEMENT param-filter (is-not-defined | text-match)?>
 * <!ATTLIST param-filter name CDATA #REQUIRED>
 *   <!-- name value: a property parameter name (e.g., "TYPE") -->
 */
class ParamFilter implements \Sabre\Xml\XmlSerializable
{
    /**
     * @var string XML element name
     * @psalm-readonly
     */
    public $xmlElement = XmlEN::PARAMFILTER;

    /**
     * @var string Parameter this filter matches on (e.g. TYPE).
     * @psalm-readonly
     */
    public $param;

    /**
     * @var ?TextMatch Filter condition. Null to match if the parameter is not defined.
     * @psalm-readonly
     */
    public $filter;

    /**
     * Constructs a ParamFilter element.
     *
     * @param string  $param The name of the parameter to match for
     * @param ?string $matchSpec The match specifier. Null to match for non-existence of the parameter, otherwise a
     *                           match specifier for TextMatch.
     * @see TextMatch
     */
    public function __construct(string $param, ?string $matchSpec)
    {
        $this->param = $param;

        if (isset($matchSpec)) {
            $this->filter = new TextMatch($matchSpec);
        }
    }

    /**
     * This function encodes the elements value (not the element itself!) to the given XML writer.
     */
    public function xmlSerialize(\Sabre\Xml\Writer $writer): void
    {
        if (isset($this->filter)) {
            $writer->write([
                'name' => XmlEN::TEXTMATCH,
                'attributes' => $this->filter->xmlAttributes(),
                'value' => $this->filter
            ]);
        } else {
            $writer->write([XmlEN::ISNOTDEFINED => null]);
        }
    }

    /**
     * Produces a list of attributes for this filter suitable to pass to a Sabre XML Writer.
     *
     * @return array<string, string> A list of attributes (attrname => attrvalue)
     */
    public function xmlAttributes(): array
    {
        return [];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120