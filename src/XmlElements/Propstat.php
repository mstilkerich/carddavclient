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
 * Represents XML DAV:propstat elements as PHP objects.
 *
 * From RFC 4918:
 * The propstat XML element MUST contain one prop XML element and one status XML element. The contents of the prop XML
 * element MUST only list the names of properties to which the result in the status element applies. The optional
 * precondition/ postcondition element and ’responsedescription’ text also apply to the properties named in ’prop’.
 *
 * ```xml
 * <!ELEMENT propstat (prop, status, error?, responsedescription?) >
 * ```
 *
 * @psalm-immutable
 *
 * @psalm-import-type DeserializedElem from Deserializers
 *
 * @package Internal\XmlElements
 */
class Propstat implements \Sabre\Xml\XmlDeserializable
{
    /**
     * Holds a single HTTP status-line.
     * @var string
     */
    public $status;

    /**
     * Contains properties related to a resource.
     * @var Prop
     */
    public $prop;

    /**
     * Constructs a Propstat element.
     *
     * @param string $status The status value of the Propstat element.
     * @param Prop $prop The Prop child element, containing the reported properties.
     */
    public function __construct(string $status, Prop $prop)
    {
        $this->status = $status;
        $this->prop = $prop;
    }

    /**
     * Deserializes the child elements of a DAV:propstat element and creates a new instance of Propstat.
     */
    public static function xmlDeserialize(\Sabre\Xml\Reader $reader): Propstat
    {
        $prop = null;
        $status = null;

        $children = $reader->parseInnerTree();
        if (is_array($children)) {
            /** @psalm-var DeserializedElem $child */
            foreach ($children as $child) {
                if ($child["value"] instanceof Prop) {
                    if (isset($prop)) {
                        throw new XmlParseException("DAV:propstat element contains multiple DAV:prop children");
                    }
                    $prop = $child["value"];
                } elseif (strcasecmp($child["name"], XmlEN::STATUS) == 0) {
                    if (isset($status)) {
                        throw new XmlParseException("DAV:propstat element contains multiple DAV:status children");
                    }

                    if (is_string($child["value"])) {
                        $status = $child["value"];
                    }
                }
            }
        }

        if (!isset($status) || !isset($prop)) {
            throw new XmlParseException("DAV:propstat element must have ONE DAV:status and one DAV:prop child");
        }

        return new self($status, $prop);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
