<?php

/**
 * Class ElementNames.
 *
 * This class serves merely as a collection of constants containing fully qualified XML
 * element names used inside this library in clark notation, i.e. including the namespace
 * as a braced prefix.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

class ElementNames
{
    public const NSDAV     = 'DAV:';
    public const NSCARDDAV = 'urn:ietf:params:xml:ns:carddav';
    public const NSCS      = 'http://calendarserver.org/ns/';

    public const CURUSRPRINC = "{" . self::NSDAV . "}current-user-principal";
    public const ABOOK_HOME = "{" . self::NSCARDDAV . "}addressbook-home-set";

    public const DISPNAME = "{" . self::NSDAV . "}displayname";
    public const RESTYPE = "{" . self::NSDAV . "}resourcetype";
    public const RESTYPE_ABOOK = "{" . self::NSCARDDAV . "}addressbook";

    public const GETCTAG = "{" . self::NSCS . "}getctag";
    public const GETETAG = "{" . self::NSDAV . "}getetag";

    public const ADD_MEMBER = "{" . self::NSDAV . "}add-member";
    public const SUPPORTED_REPORT_SET = "{" . self::NSDAV . "}supported-report-set";
    public const SUPPORTED_REPORT = "{" . self::NSDAV . "}supported-report";
    public const REPORT = "{" . self::NSDAV . "}report";
    public const REPORT_SYNCCOLL = "{" . self::NSDAV . "}sync-collection";
    public const REPORT_MULTIGET = "{" . self::NSCARDDAV . "}addressbook-multiget";

    public const SYNCTOKEN = "{" . self::NSDAV . "}sync-token";
    public const SYNCLEVEL = "{" . self::NSDAV . "}sync-level";

    public const SUPPORTED_ADDRDATA = "{" . self::NSCARDDAV . "}supported-address-data";
    public const ADDRDATA = "{" . self::NSCARDDAV . "}address-data";
    public const VCFPROP = "{" . self::NSCARDDAV . "}prop";
    public const ABOOK_DESC = "{" . self::NSCARDDAV . "}addressbook-description";
    public const MAX_RESSIZE = "{" . self::NSCARDDAV . "}max-resource-size";

    public const MULTISTATUS = "{" . self::NSDAV . "}multistatus";
    public const RESPONSE = "{" . self::NSDAV . "}response";
    public const PROPFIND = "{" . self::NSDAV . "}propfind";
    public const PROPSTAT = "{" . self::NSDAV . "}propstat";
    public const PROP = "{" . self::NSDAV . "}prop";
    public const HREF = "{" . self::NSDAV . "}href";
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
