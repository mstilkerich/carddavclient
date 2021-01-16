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
 * Represents the filter conditions to an addressbook-query report.
 *
 * Can either be the top-level filter list of prop-filter elements, or a filter list contained within a prop-filter.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

/**
 * @psalm-type PropName = string
 * @psalm-type NotDefined = null
 * @psalm-type TextMatch = string
 * @psalm-type ParamFilter = array{string, NotDefined | TextMatch}
 * @psalm-type PropFilter = QueryConditions
 * @psalm-type QueryCondition = array{PropName, NotDefined | TextMatch | ParamFilter | QueryConditions}
 * @psalm-type ConditionsParam = list<QueryCondition> | array<string, QueryCondition>
 */
class QueryConditions
{
    /********* PROPERTIES *********/
    /** @var "allof" | "anyof" Semantics of match for multiple conditions (AND or OR). */
    private $allNeeded;

    /** @var list<QueryCondition> */
    private $conditions = [];

    /********* PUBLIC FUNCTIONS *********/

    /**
     * Constructs a filter condition list.
     *
     * For ease of use, the $conditions parameter can take a simple form, which allows exactly one match criterion per
     * VCard property. Or it can take a more elaborate form where for each property, _several_ lists of match criteria
     * can be defined.
     *
     * Note that property names can be prefixed with a group name like "GROUP.EMAIL" to only match properties that
     * belong to the given group. If no group prefix is given, the match applies to all properties of the type,
     * independent of whether they belong to a group or not.
     *
     * The simple form is an associative array mapping property/parameter names to null or a pattern match string.
     *   - The property/parameter name can be prefixed with an ! to invert the match.
     *   - If the value is null, the property/parameter name will be checked for existence (non-existence if inverted)
     *   - If the value is a string, a case-insensitive pattern match will be performed.
     *     - "abc"   - The property/parameter must match the value "abc" exactly
     *     - "abc%"  - The property/parameter must start with "abc"
     *     - "%abc"  - The property/parameter must end with "abc"
     *     - "%abc%" - The property/parameter must contain "abc"
     *   - Only for properties: If the value is a two-element array{string,?string} where the first element is the name
     *     of a parameter and the second is a match string or null with a meaning as above, then the property filter
     *     applies only to properties that have a parameter of the given name, matching the value (if given).
     *   - Only for properties: A QueryConditions object that defines a list of match criteria of which all or any need
     *     to match, depending on the $allNeeded setting of that object.
     *
     * Examples:
     *   [ 'EMAIL' => null ] - matches all VCards that do NOT have an EMAIL property
     *   [ '!EMAIL' => null ] - matches all VCards that DO have an EMAIL property
     *   [ 'EMAIL' => '%@example.com' ] - matches all VCards that have an EMAIL property with an email address of the
     *                                    example.com domain
     *   [ 'EMAIL' => '%@example.com', 'N' => 'Mustermann;%' ] - like before, but additionally/alternatively the surname
     *                                                           must be Mustermann (depending on $allNeeded)
     *   [ 'EMAIL' => [ 'TYPE' => 'home' ] ] - matches all VCards with an EMAIL property that has a TYPE parameter with
     *                                         value home
     *
     * The more elaborate form is given an array of two-element arrays where the first element is a property name and
     * the second element is any of the values possible in the simple form, or a QueryConditions object with a list of
     * conditions of which all/any need to apply. The list may contain several entries for the same property.
     *
     * Example:
     *   [ [ 'EMAIL´, new QueryConditions([ '%@example.com', ['TYPE', 'home'] ], true) ], [ 'N', 'Mustermann;%' ] ] -
     *     Matches all VCards, that have an EMAIL property where with an address in the domain example.com and at the
     *     same time a TYPE parameter with value home, and/or an N property with a surname of Mustermann.
     *
     *
     * @param array $conditions The match conditions for the query, or for one property filter. An empty array
     *                          will cause all VCards to match.
     * @param bool $allNeeded Whether all or any of the conditions needs to match.
     */
    public function __construct(array $conditions, bool $allNeeded = false)
    {
        $this->allNeeded = $allNeeded ? 'allof' : 'anyof';

        foreach ($conditions as $name => $condition) {
            if (is_int($name)) {
                if (is_array($condition) && count($condition) == 2) {
                    [ $name, $condition ] = $condition;
                } else {
                    throw new \InvalidArgumentException(
                        "Condition in elaborate form must be array of property name and condition"
                    );
                }
            }

            if (!is_string($name)) {
                throw new \InvalidArgumentException("Property name must be a string, got: " . print_r($name, true));
            }

            $oldCondCount = count($this->conditions);

            if (!isset($condition)) {
                $this->conditions[] = [ $name, $condition ];
            } elseif (is_string($condition)) {
                $this->conditions[] = [ $name, $condition ];
            } elseif ($condition instanceof QueryConditions) {
                $this->conditions[] = [ $name, $condition ];
            } elseif (is_array($condition) && count($condition) == 2) {
                [ $paramname, $paramcond ] = $condition;
                if (is_string($paramname)) {
                    if (!isset($paramcond)) {
                        $this->conditions[] = [ $name, [ $paramname, $paramcond ] ];
                    } elseif (is_string($paramcond)) {
                        $this->conditions[] = [ $name, [ $paramname, $paramcond ] ];
                    }
                }
            }

            if (count($this->conditions) == $oldCondCount) {
                throw new \InvalidArgumentException(
                    "Invalid condition for property $name given" . print_r($condition, true)
                );
            }
        }
    }

    /**
     * Produces a data structure usable with sabre/xml to create a list of CARDDAV:prop-filter elements or a list of
     * filter condition elements (CARDDAV:is-not-defined, CARDDAV:param-filter, CARDDAV:text-match) within a
     * prop-filter, depending on $propname.
     *
     * @param ?string $propname If this is a list of filter conditions for a specific VCard property (i.e. at the
     *                          CARDDAV:prop-filter level), gives the name of the corresponding VCard property. If this
     *                          is a condition list at the top-level (i.e. the CARDDAV:filter level), this property
     *                          must be null.
     * @return list<array{name: string, attributes: array<string, string>, value: ?array}>
     */
    public function toFilterElements(?string $propname = null): array
    {
        $ret = [];

        foreach ($this->conditions as $condition) {
            [ $name, $condition ] = $condition;

            $invertMatch = false;
            if ($name[0] == "!") {
                $invertMatch = true;
                $name = substr($name, 1);
            }

            if (strlen($name) == 0) {
                throw new \InvalidArgumentException("Property/parameter name must not be empty");
            }

            if (isset($propname)) {
                throw new \Exception("not implemented");
            } else {
                if ($condition instanceof QueryConditions) {
                    $ret[] = [
                        'name' => XmlEN::PROPFILTER,
                        'attributes' => [ 'name' => $name, 'test' => $condition->allNeeded ],
                        'value' => $condition->toFilterElements($name)
                    ];
                } else {
                    $ret[] = [
                        'name' => XmlEN::PROPFILTER,
                        'attributes' => [ 'name' => $name ],
                        'value' => $this->toMatchConditionElement($invertMatch, false, $condition)
                    ];
                }
            }
        }

        return $ret;
    }

    /**
     * Produces a data structure usable with sabre/xml for any of:
     *   - a CARDDAV:text-match element
     *               (attributes: negate-condition="yes/no" match-type="equals/contains/starts-with/ends-with")
     *   - a CARDDAV:is-not-defined element
     *   - a CARDDAV:param-filter element
     *
     *   @param bool $invertMatch True if the match condition shall be inverted
     *   @param NotDefined | TextMatch | ParamFilter $condition
     */
    private function toMatchConditionElement(bool $invertMatch, bool $inParamFilter, $condition): ?array
    {
        if (isset($condition)) {
            if (is_string($condition)) {
                // textual match - determine match type
                $matchtype = 0;
                $matchtypes = [ 'equals', 'ends-with', 'starts-with', 'contains' ];
                if (strlen($condition) > 0 && $condition[0] == '%') {
                    $condition = substr($condition, 1);
                    $matchtype += 1;
                }

                if (strlen($condition) > 0 && $condition[-1] == '%') {
                    $condition = substr($condition, 0, -1);
                    $matchtype += 2;
                }

                // empty string as text-match value not forbidden by RFC 6352
                return [
                    'name' => XmlEN::TEXTMATCH,
                    'attributes' => [
                        'negate-condition' => $invertMatch ? 'yes' : 'no',
                        'match-type' => $matchtypes[$matchtype]
                    ],
                    'value' => $condition
                ];
            } else {
                // param-filter - only allowed within prop-filter
                if ($inParamFilter) {
                    throw new \InvalidArgumentException("A parameter filter can only be used at property filter level");
                }

                [ $param, $condition ] = $condition;
                return [
                    'name' => XmlEN::PARAMFILTER,
                    'attributes' => [ 'name' => $param ],
                    'value' => $this->toMatchConditionElement($invertMatch, true, $condition)
                ];
            }
        } else {
            if ($invertMatch) {
                // assert that the property/parameter exists -> empty prop-filter / param-filter element
                return null;
            } else {
                // assert that the property/parameter does not exist -> is-not-defined element
                return [ 'name' => XmlEN::ISNOTDEFINED, 'value' => null ];
            }
        }
    }

    /**
     * Produces a data structure usable with sabre/xml to create a list of attributes for either the CARDDAV:filter or
     * the CARDDAV:prop-filter element.
     *
     * The attributes produced are: test="allof/anyof"
     *                              name="$propname" - only if $propname is not null
     *
     * @return array<string, string> A list of properties to be passed to Sabre XML service
     */
    public function toFilterAttributes(?string $propname = null): array
    {
        $ret = [ 'test' => $this->allNeeded ];
        if (isset($propname)) {
            $ret['name'] = $propname;
        }
        return $ret;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
