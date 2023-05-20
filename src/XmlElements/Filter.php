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
 * Represents XML urn:ietf:params:xml:ns:carddav:filter elements as PHP objects (RFC 6352).
 *
 * From RFC 6352:
 * The "filter" element specifies the search filter used to match address objects that should be returned by a report.
 * The "test" attribute specifies whether any (logical OR) or all (logical AND) of the prop-filter tests need to match
 * in order for the overall filter to match.
 *
 * <!ELEMENT filter (prop-filter*)>
 * <!ATTLIST filter test (anyof | allof) "anyof">
 *   <!-- test value:
 *     anyof logical OR for prop-filter matches
 *     allof logical AND for prop-filter matches -->
 *
 * @psalm-type PropName = string
 * @psalm-type NotDefined = null
 * @psalm-type TextMatchSpec = string
 * @psalm-type ParamFilterSpec = array{string, NotDefined | TextMatchSpec}
 *
 * @psalm-type SimpleCondition = NotDefined|TextMatchSpec|ParamFilterSpec
 * @psalm-type SimpleConditions = array<PropName, SimpleCondition>
 * @psalm-type ComplexCondition = array{matchAll?: bool} & array<int,SimpleCondition>
 * @psalm-type ComplexConditions = list<array{PropName,ComplexCondition}>
 *
 * @package Internal\XmlElements
 */
class Filter implements \Sabre\Xml\XmlSerializable
{
    /**
     * Semantics of match for multiple conditions (AND or OR).
     * @psalm-var 'anyof'|'allof'
     * @var string
     * @psalm-readonly
     */
    public $testType;

    /**
     * The PropFilter child elements of this filter.
     * @psalm-var list<PropFilter>
     * @var array<int,PropFilter>
     * @psalm-readonly
     */
    public $propFilters = [];

    /**
     * Constructs a Filter consisting of zero or more PropFilter elements.
     *
     * For ease of use, the $conditions parameter can take a simple form, which allows exactly one match criterion per
     * VCard property. Or it can take a more elaborate form where for each property, _several_ lists of match criteria
     * can be defined.
     *
     * Note that property names can be prefixed with a group name like "GROUP.EMAIL" to only match properties that
     * belong to the given group. If no group prefix is given, the match applies to all properties of the type,
     * independent of whether they belong to a group or not.
     *
     * __Simple form__
     *
     * The simple form is an associative array mapping property names to null or a filter condition.
     *
     * A filter condition can either be a string with a text match specification (see TextMatch constructor for format)
     * or a two-element array{string,?string} where the first element is the name of a parameter and the second is a
     * string for TextMatch or null with a meaning as for a property filter.
     *
     * Examples for the simple form:
     *  - `['EMAIL' => null]`: Matches all VCards that do NOT have an EMAIL property
     *  - `['EMAIL' => "//"]`: Matches all VCards that DO have an EMAIL property (with any value)
     *  - `['EMAIL' => '/@example.com/$']`:
     *     Matches all VCards that have an EMAIL property with an email address of the example.com domain
     *  - `['EMAIL' => '/@example.com/$', 'N' => '/Mustermann;/^']`:
     *     Like before, but additionally/alternatively the surname must be Mustermann (depending on $matchAll)
     *  - `['EMAIL' => ['TYPE' => '/home/=']]`:
     *     Matches all VCards with an EMAIL property that has a TYPE parameter with value home
     *
     * __Elaborate form__
     *
     * The more elaborate form is an array of two-element arrays where the first element is a property name and
     * the second element is any of the values possible in the simple form, or an array object with a list of
     * conditions of which all/any need to apply, plus an optional key "matchAll" that can be set to true to indicate
     * that all conditions need to match (AND semantics).
     *
     * Examples for the elaborate form:
     *  - `[['EMAIL', ['/@example.com/$', ['TYPE', '/home/='], 'matchAll' => true]], ['N', '/Mustermann;/^']]`:
     *     Matches all VCards, that have an EMAIL property with an address in the domain example.com and at the same
     *     time a TYPE parameter with value home, and/or an N property with a surname of Mustermann.
     *
     * It is also possible to mix both forms, where string keys are used for the simple form and numeric indexes are
     * used for the elaborate form filters.
     *
     * @psalm-param SimpleConditions|ComplexConditions $conditions
     * @param array $conditions
     *  The match conditions for the query, or for one property filter. An empty array will cause all VCards to match.
     * @param bool $matchAll Whether all or any of the conditions needs to match.
     */
    public function __construct(array $conditions, bool $matchAll)
    {
        $this->testType = $matchAll ? 'allof' : 'anyof';

        foreach ($conditions as $idx => $condition) {
            if (is_string($idx)) {
                // simple form - single condition only
                /** @psalm-var SimpleCondition $condition */
                $this->propFilters[] = new PropFilter($idx, [$condition]);
            } elseif (is_array($condition) && count($condition) == 2) {
                // elaborate form [ property name, list of simple conditions ]
                [ $propname, $simpleConditions ] = $condition;
                /** @psalm-var ComplexCondition $simpleConditions */
                $this->propFilters[] = new PropFilter($propname, $simpleConditions);
            } else {
                throw new \InvalidArgumentException("Invalid complex condition: " . var_export($condition, true));
            }
        }
    }

    /**
     * This function encodes the element's value (not the element itself!) to the given XML writer.
     */
    public function xmlSerialize(\Sabre\Xml\Writer $writer): void
    {
        foreach ($this->propFilters as $propFilter) {
            $writer->write([
                'name' => XmlEN::PROPFILTER,
                'attributes' => $propFilter->xmlAttributes(),
                'value' => $propFilter
            ]);
        }
    }

    /**
     * Produces a list of attributes for this filter suitable to pass to a Sabre XML Writer.
     *
     * The attributes produced are:
     *  - `test="allof/anyof"`
     *
     * @return array<string, string> A list of attributes (attrname => attrvalue)
     */
    public function xmlAttributes(): array
    {
        return [ 'test' => $this->testType ];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
