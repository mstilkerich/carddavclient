<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;
use MStilkerich\CardDavClient\Exception\XmlParseException;

/**
 * Represents XML urn:ietf:params:xml:ns:carddav:param-filter elements as PHP objects (RFC 6352).
 *
 * From RFC 6352:
 * The CARDDAV:param-filter XML element specifies search criteria on a specific vCard property parameter (e.g., TYPE) in
 * the scope of a given CARDDAV:prop-filter. A vCard property is said to match a CARDDAV:param-filter if:
 *   - A parameter of the type specified by the "name" attribute exists, and the CARDDAV:param-filter is empty, or it
 *     matches the CARDDAV:text-match conditions if specified.
 *   or:
 *   - A parameter of the type specified by the "name" attribute does not exist, and the CARDDAV:is-not-defined element
 *     is specified.
 *
 * ```xml
 * <!ELEMENT param-filter (is-not-defined | text-match)?>
 * <!ATTLIST param-filter name CDATA #REQUIRED>
 *   <!-- name value: a property parameter name (e.g., "TYPE") -->
 * ```
 *
 * @package Internal\XmlElements
 */
class ParamFilter implements \Sabre\Xml\XmlSerializable
{
    /**
     * Parameter this filter matches on (e.g. TYPE).
     * @var string
     * @psalm-readonly
     */
    public $param;

    /**
     * Filter condition. Null to match if the parameter is not defined.
     * @var ?TextMatch
     * @psalm-readonly
     */
    public $filter;

    /**
     * Constructs a ParamFilter element.
     *
     * @param string  $param The name of the parameter to match for
     * @param ?string $matchSpec
     *  The match specifier. Null to match for non-existence of the parameter, otherwise a match specifier for
     *  {@see TextMatch}.
     */
    public function __construct(string $param, ?string $matchSpec)
    {
        $this->param = $param;

        if (isset($matchSpec)) {
            $this->filter = new TextMatch($matchSpec);
        }
    }

    /**
     * This function encodes the element's value (not the element itself!) to the given XML writer.
     */
    public function xmlSerialize(\Sabre\Xml\Writer $writer): void
    {
        if (isset($this->filter)) {
            $this->filter->xmlSerializeElement($writer);
        } else {
            $writer->write([XmlEN::ISNOTDEFINED => null]);
        }
    }

    /**
     * This function serializes the full element to the given XML writer.
     */
    public function xmlSerializeElement(\Sabre\Xml\Writer $writer): void
    {
        $writer->write([
            'name' => XmlEN::PARAMFILTER,
            'attributes' => $this->xmlAttributes(),
            'value' => $this
        ]);
    }

    /**
     * Produces a list of attributes for this filter suitable to pass to a Sabre XML Writer.
     *
     * @return array<string, string> A list of attributes (attrname => attrvalue)
     */
    public function xmlAttributes(): array
    {
        return [ 'name' => $this->param ];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
