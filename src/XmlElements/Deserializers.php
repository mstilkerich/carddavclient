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
 * Class Deserializers.
 *
 * This class contains static deserializer functions to be used with Sabre/XML.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

use Sabre\Xml\Reader as Reader;
use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

class Deserializers
{
    public static function deserializeHrefSingle(Reader $reader): ?string
    {
        $hrefs = self::deserializeHrefMulti($reader);
        return $hrefs[0] ?? null;
    }

    public static function deserializeHrefMulti(Reader $reader): array
    {
        $hrefs = [];
        $children = $reader->parseInnerTree();
        if (is_array($children)) {
            foreach ($children as $child) {
                if (strcasecmp($child["name"], XmlEN::HREF) == 0) {
                    $hrefs[] = $child["value"];
                }
            }
        }

        return $hrefs;
    }

    /**
     * Deserializes XML DAV:supported-report-set elements to an array. (RFC3253)
     *
     * Per RFC3252, arbitrary report element types are nested within the DAV:supported-report-set element.
     * Example:
     *  <supported-report-set>
     *    <supported-report>           <--- 0+ supported-report elements
     *      <report>                   <--- 1  each containing exactly one report element
     *        <sync-collection/>       <--- 1  containing exactly one ANY element for the corresponding report
     *      </report>
     *    </supported-report>
     *    <supported-report>
     *      <report>
     *        <addressbook-multiget xmlns="urn:ietf:params:xml:ns:carddav"/>
     *      </report>
     *    </supported-report>
     *  </supported-report-set>
     *
     *  @return array
     *   Array with the element names of the supported reports.
     */
    public static function deserializeSupportedReportSet(Reader $reader)
    {
        $srs = [];
        $supportedReports = $reader->parseInnerTree();

        // First run over all the supported-report elements (there is one for each supported report)
        if (is_array($supportedReports)) {
            foreach ($supportedReports as $supportedReport) {
                if (strcasecmp($supportedReport['name'], XmlEN::SUPPORTED_REPORT) === 0) {
                    if (is_array($supportedReport['value'])) {
                        // Second run over all the report elements (there should be exactly one per RFC3253)
                        foreach ($supportedReport['value'] as $report) {
                            if (strcasecmp($report['name'], XmlEN::REPORT) === 0) {
                                if (is_array($report['value'])) {
                                    // Finally, get the actual element specific for the supported report
                                    // (there should be exactly one per RFC3253)
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
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
