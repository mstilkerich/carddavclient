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
 * Represents XML urn:ietf:params:xml:ns:carddav:prop-filter elements as PHP objects (RFC 6352).
 *
 * From RFC 6352:
 * The CARDDAV:prop-filter XML element specifies search criteria on a specific vCard property (e.g., "NICKNAME"). An
 * address object is said to match a CARDDAV:prop-filter if:
 *   - A vCard property of the type specified by the "name" attribute exists, and the CARDDAV:prop-filter is empty, or
 *     it matches any specified CARDDAV:text-match or CARDDAV:param-filter conditions. The "test" attribute specifies
 *     whether any (logical OR) or all (logical AND) of the text-filter and param- filter tests need to match in order
 *     for the overall filter to match.
 *   Or:
 *   - A vCard property of the type specified by the "name" attribute does not exist, and the CARDDAV:is-not-defined
 *     element is specified.
 *
 * vCard allows a "group" prefix to appear before a property name in the vCard data. When the "name" attribute does not
 * specify a group prefix, it MUST match properties in the vCard data without a group prefix or with any group prefix.
 * When the "name" attribute includes a group prefix, it MUST match properties that have exactly the same group prefix
 * and name. For example, a "name" set to "TEL" will match "TEL", "X-ABC.TEL", "X-ABC-1.TEL" vCard properties. A
 * "name" set to "X-ABC.TEL" will match an "X-ABC.TEL" vCard property only, it will not match "TEL" or "X-ABC-1.TEL".
 *
 * ```xml
 * <!ELEMENT prop-filter (is-not-defined | (text-match*, param-filter*))>
 * <!ATTLIST prop-filter name CDATA #REQUIRED
 *                           test (anyof | allof) "anyof">
 *     <!-- name value: a vCard property name (e.g., "NICKNAME")
 *          test value:
 *            anyof logical OR for text-match/param-filter matches
 *            allof logical AND for text-match/param-filter matches -->
 * ```
 * @psalm-import-type ComplexCondition from Filter
 *
 * @package Internal\XmlElements
 */
class PropFilter implements \Sabre\Xml\XmlSerializable
{
    /**
     * Semantics of match for multiple conditions (AND or OR).
     *
     * @psalm-var 'anyof'|'allof'
     * @var string
     * @psalm-readonly
     */
    public $testType = 'anyof';

    /**
     * Property this filter matches on (e.g. EMAIL), including optional group prefix (e.g. G1.EMAIL).
     * @var string
     * @psalm-readonly
     */
    public $property;

    /**
     * List of filter conditions. Null to match if the property is not defined.
     * @psalm-var null|list<TextMatch|ParamFilter>
     * @var null|array<int, TextMatch|ParamFilter>
     * @psalm-readonly
     */
    public $conditions;

    /**
     * Constructs a PropFilter element.
     *
     * The $conditions parameter is an array of all the filter conditions for this property filter. An empty array
     * causes the filter to always match. Otherwise, the $conditions array has entries according to the ComplexFilter /
     * elaborate form described in the {@see Filter} class.
     *
     * @param string $propname The name of the VCard property this filter matches on.
     * @psalm-param ComplexCondition $conditions
     * @param array $conditions The match conditions for the property
     */
    public function __construct(string $propname, array $conditions)
    {
        if (strlen($propname) > 0) {
            $this->property = $propname;
        } else {
            throw new \InvalidArgumentException("Property name must be a non-empty string");
        }

        if ($conditions["matchAll"] ?? false) {
            $this->testType = 'allof';
        }

        foreach ($conditions as $idx => $condition) {
            if (is_string($idx)) { // matchAll
                continue;
            }

            if (isset($condition)) {
                if (is_array($condition)) {
                    // param filter
                    if (count($condition) == 2) {
                        [ $paramname, $paramcond ] = $condition;
                        $this->conditions[] = new ParamFilter($paramname, $paramcond);
                    } else {
                        throw new \InvalidArgumentException(
                            "Param filter on property $propname must be an element of two entries" .
                            var_export($condition, true)
                        );
                    }
                } elseif (is_string($condition)) {
                    // text match filter
                    $this->conditions[] = new TextMatch($condition);
                } else {
                    throw new \InvalidArgumentException(
                        "Invalid condition for property $propname: " . var_export($condition, true)
                    );
                }
            } else {
                // is-not-defined filter
                if (count($conditions) > 1) {
                    throw new \InvalidArgumentException(
                        "PropFilter on $propname can have ONE not-defined (null) OR several match conditions: " .
                        var_export($conditions, true)
                    );
                }
                $this->conditions = null;
                break;
            }
        }
    }

    /**
     * This function encodes the element's value (not the element itself!) to the given XML writer.
     */
    public function xmlSerialize(\Sabre\Xml\Writer $writer): void
    {
        if (isset($this->conditions)) {
            foreach ($this->conditions as $condition) {
                // either ParamFilter or TextMatch
                $condition->xmlSerializeElement($writer);
            }
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
        return [ 'name' => $this->property, 'test' => $this->testType ];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
