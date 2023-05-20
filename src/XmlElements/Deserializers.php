<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

use Sabre\Xml\Reader as Reader;
use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

/**
 * Contains static deserializer functions to be used with Sabre/XML.
 *
 * @psalm-type DeserializedElem = array{
 *   name: string,
 *   attributes: array<string, string>,
 *   value: mixed
 * }
 *
 * @package Internal\XmlElements
 */
class Deserializers
{
    /**
     * Deserializes a single DAV:href child element to a string.
     *
     * @return ?string
     *  If no href child element is present, null is returned. If multiple href child elements are present, the value of
     *  the first one is returned.
     */
    public static function deserializeHrefSingle(Reader $reader): ?string
    {
        $hrefs = self::deserializeHrefMulti($reader);
        return $hrefs[0] ?? null;
    }

    /**
     * Deserializes a multiple DAV:href child elements to an array of strings.
     *
     * @psalm-return list<string>
     * @return array<int,string>
     *  An array of strings, each representing the value of a href child element. Empty array if no href child elements
     *  present.
     */
    public static function deserializeHrefMulti(Reader $reader): array
    {
        $hrefs = [];
        $children = $reader->parseInnerTree();
        if (is_array($children)) {
            /** @psalm-var DeserializedElem $child */
            foreach ($children as $child) {
                if (strcasecmp($child["name"], XmlEN::HREF) == 0) {
                    if (is_string($child["value"])) {
                        $hrefs[] = $child["value"];
                    }
                }
            }
        }

        return $hrefs;
    }

    /**
     * Deserializes XML DAV:supported-report-set elements to an array (RFC3253).
     *
     * Per RFC3252, arbitrary report element types are nested within the DAV:supported-report-set element.
     * Example:
     * ```xml
     *  <supported-report-set>
     *    <supported-report>      <--- 0+ supported-report elements -->
     *      <report>              <--- 1  each containing exactly one report element -->
     *        <sync-collection/>  <--- 1  containing exactly one ANY element for the corresponding report -->
     *      </report>
     *    </supported-report>
     *    <supported-report>
     *      <report>
     *        <addressbook-multiget xmlns="urn:ietf:params:xml:ns:carddav"/>
     *      </report>
     *    </supported-report>
     *  </supported-report-set>
     * ```
     *
     * @psalm-return list<string>
     * @return array<int,string> Array with the element names of the supported reports.
     */
    public static function deserializeSupportedReportSet(Reader $reader): array
    {
        $srs = [];

        $supportedReports = $reader->parseInnerTree();

        // First run over all the supported-report elements (there is one for each supported report)
        if (is_array($supportedReports)) {
            /** @psalm-var DeserializedElem $supportedReport */
            foreach ($supportedReports as $supportedReport) {
                if (strcasecmp($supportedReport['name'], XmlEN::SUPPORTED_REPORT) === 0) {
                    if (is_array($supportedReport['value'])) {
                        // Second run over all the report elements (there should be exactly one per RFC3253)
                        /** @psalm-var DeserializedElem $report */
                        foreach ($supportedReport['value'] as $report) {
                            if (strcasecmp($report['name'], XmlEN::REPORT) === 0) {
                                if (is_array($report['value'])) {
                                    // Finally, get the actual element specific for the supported report
                                    // (there should be exactly one per RFC3253)
                                    /** @psalm-var DeserializedElem $reportelem */
                                    foreach ($report['value'] as $reportelem) {
                                        $srs[] = $reportelem["name"];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $srs;
    }

    /**
     * Deserializes an XML element to an array of its attributes, discarding its contents.
     *
     *  @return array<string, string> Mapping attribute names to values.
     */
    public static function deserializeToAttributes(Reader $reader): array
    {
        /** @var array<string,string> */
        $attributes = $reader->parseAttributes();
        $reader->next();
        return $attributes;
    }

    /**
     * Deserializes a CARDDAV:supported-address-data element (RFC 6352).
     *
     * It contains one or more CARDDAV:address-data-type elements.
     */
    public static function deserializeSupportedAddrData(Reader $reader): array
    {
        return \Sabre\Xml\Deserializer\repeatingElements($reader, XmlEN::ADDRDATATYPE);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
