<?php

/**
 * Class to represent XML DAV:supported-report-set elements as PHP objects. (RFC3253)
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
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

class SupportedReportSet implements \Sabre\Xml\XmlDeserializable
{
    public static function xmlDeserialize(\Sabre\Xml\Reader $reader)
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
