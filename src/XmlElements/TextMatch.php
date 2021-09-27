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
 * Represents XML urn:ietf:params:xml:ns:carddav:text-match elements as PHP objects (RFC 6352).
 *
 * From RFC 6352:
 * The CARDDAV:text-match XML element specifies text used for a substring match against the vCard
 * property or parameter value specified in an address book REPORT request.
 *
 * The "collation" attribute is used to select the collation that the server MUST use for character string matching. In
 * the absence of this attribute, the server MUST use the "i;unicode-casemap" collation.
 *
 * The "negate-condition" attribute is used to indicate that this test returns a match if the text matches, when the
 * attribute value is set to "no", or return a match if the text does not match, if the attribute value is set to "yes".
 * For example, this can be used to match components with a CATEGORIES property not set to PERSON.
 *
 * The "match-type" attribute is used to indicate the type of match operation to use.  Possible choices are:
 *   - "equals" - an exact match to the target string
 *   - "contains" - a substring match, matching anywhere within the target string
 *   - "starts-with" - a substring match, matching only at the start of the target string
 *   - "ends-with" - a substring match, matching only at the end of the target string
 *
 * ```xml
 *     <!ELEMENT text-match (#PCDATA)>
 *     <!-- PCDATA value: string -->
 *     <!ATTLIST text-match
 *        collation CDATA "i;unicode-casemap"
 *        negate-condition (yes | no) "no"
 *        match-type (equals|contains|starts-with|ends-with) "contains">
 * ```
 * @package Internal\XmlElements
 */
class TextMatch implements \Sabre\Xml\XmlSerializable
{
    /**
     * Collation to use for comparison (currently constant)
     * @var string
     * @psalm-readonly
     */
    public $collation = 'i;unicode-casemap';

    /**
     * Whether to invert the result of the match
     * @var bool
     * @psalm-readonly
     */
    public $invertMatch = false;

    /**
     * The type of text match to apply
     * @psalm-var 'equals' | 'contains' | 'starts-with' | 'ends-with'
     * @var string
     * @psalm-readonly
     */
    public $matchType = 'contains';

    /**
     * The string to match for
     * @var string
     * @psalm-readonly
     */
    public $needle = '';

    /**
     * Constructs a TextMatch element.
     *
     * The match is specified in a string form that encodes all properties of the match.
     *   - The match string must be enclosed in / (e.g. `/foo/`)
     *   - The / character has no special meaning other than to separate the match string from modifiers. No escaping is
     *     needed if / appears as part of the match string (e.g. `/http:///` matches for "http://").
     *   - To invert the match, insert ! before the initial / (e.g. `!/foo/`)
     *   - The default match type is "contains" semantics. If you want to match the start or end of the property value,
     *     or perform an exact match, use the ^/$/= modifiers after the final slash. Examples:
     *       - `/abc/=`: The property/parameter must match the value "abc" exactly
     *       - `/abc/^`: The property/parameter must start with "abc"
     *       - `/abc/$`: The property/parameter must end with "abc"
     *       - `/abc/`: The property/parameter must contain "abc"
     *   - The matching is performed case insensitive with UTF8 character set (this is currently not changeable).
     *
     * @param string $matchSpec Specification of the text match that encodes all properties of the match.
     */
    public function __construct(string $matchSpec)
    {
        if (preg_match('/^(!?)\/(.*)\/([$=^]?)$/', $matchSpec, $matches)) {
            if (count($matches) === 4) {
                [ , $inv, $needle, $matchType ] = $matches;

                $this->invertMatch = ($inv == "!");
                $this->needle = $needle;

                if ($matchType == '^') {
                    $this->matchType = 'starts-with';
                } elseif ($matchType == '$') {
                    $this->matchType = 'ends-with';
                } elseif ($matchType == '=') {
                    $this->matchType = 'equals';
                } else {
                    $this->matchType = 'contains';
                }

                return;
            }
        }

        throw new \InvalidArgumentException("Not a valid match specifier for TextMatch: $matchSpec");
    }

    /**
     * This function encodes the element's value (not the element itself!) to the given XML writer.
     */
    public function xmlSerialize(\Sabre\Xml\Writer $writer): void
    {
        $writer->write($this->needle);
    }

    /**
     * This function serializes the full element to the given XML writer.
     */
    public function xmlSerializeElement(\Sabre\Xml\Writer $writer): void
    {
        if (strlen($this->needle) > 0) {
            $writer->write([
                'name' => XmlEN::TEXTMATCH,
                'attributes' => $this->xmlAttributes(),
                'value' => $this
            ]);
        }
    }

    /**
     * Produces a list of attributes for this filter suitable to pass to a Sabre XML Writer.
     *
     * @return array<string, string> A list of attributes (attrname => attrvalue)
     */
    public function xmlAttributes(): array
    {
        return [
            'negate-condition' => ($this->invertMatch ? 'yes' : 'no'),
            'collation' => $this->collation,
            'match-type' => $this->matchType
        ];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
