<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

/**
 * Defines constants with fully-qualified XML element names.
 *
 * The syntax used is clark notation, i.e. including the namespace as a braced prefix. This syntax is understood by the
 * Sabre libraries.
 *
 * @package Internal\XmlElements
 */
class ElementNames
{
    /** @var string */
    public const NSDAV     = 'DAV:';
    /** @var string */
    public const NSCARDDAV = 'urn:ietf:params:xml:ns:carddav';
    /** @var string */
    public const NSCS      = 'http://calendarserver.org/ns/';

    /** @var string */
    public const CURUSRPRINC = "{" . self::NSDAV . "}current-user-principal";
    /** @var string */
    public const ABOOK_HOME = "{" . self::NSCARDDAV . "}addressbook-home-set";

    /** @var string */
    public const DISPNAME = "{" . self::NSDAV . "}displayname";
    /** @var string */
    public const RESTYPE = "{" . self::NSDAV . "}resourcetype";
    /** @var string */
    public const RESTYPE_COLL = "{" . self::NSDAV . "}collection";
    /** @var string */
    public const RESTYPE_ABOOK = "{" . self::NSCARDDAV . "}addressbook";

    /** @var string */
    public const GETCTAG = "{" . self::NSCS . "}getctag";
    /** @var string */
    public const GETETAG = "{" . self::NSDAV . "}getetag";

    /** @var string */
    public const ADD_MEMBER = "{" . self::NSDAV . "}add-member";
    /** @var string */
    public const SUPPORTED_REPORT_SET = "{" . self::NSDAV . "}supported-report-set";
    /** @var string */
    public const SUPPORTED_REPORT = "{" . self::NSDAV . "}supported-report";
    /** @var string */
    public const REPORT = "{" . self::NSDAV . "}report";
    /** @var string */
    public const REPORT_SYNCCOLL = "{" . self::NSDAV . "}sync-collection";
    /** @var string */
    public const REPORT_MULTIGET = "{" . self::NSCARDDAV . "}addressbook-multiget";
    /** @var string */
    public const REPORT_QUERY = "{" . self::NSCARDDAV . "}addressbook-query";

    /** @var string */
    public const SYNCTOKEN = "{" . self::NSDAV . "}sync-token";
    /** @var string */
    public const SYNCLEVEL = "{" . self::NSDAV . "}sync-level";

    /** @var string */
    public const SUPPORTED_ADDRDATA = "{" . self::NSCARDDAV . "}supported-address-data";
    /** @var string */
    public const ADDRDATA = "{" . self::NSCARDDAV . "}address-data";
    /** @var string */
    public const ADDRDATATYPE = "{" . self::NSCARDDAV . "}address-data-type";
    /** @var string */
    public const VCFPROP = "{" . self::NSCARDDAV . "}prop";
    /** @var string */
    public const ABOOK_DESC = "{" . self::NSCARDDAV . "}addressbook-description";
    /** @var string */
    public const MAX_RESSIZE = "{" . self::NSCARDDAV . "}max-resource-size";

    /** @var string */
    public const MULTISTATUS = "{" . self::NSDAV . "}multistatus";
    /** @var string */
    public const RESPONSE = "{" . self::NSDAV . "}response";
    /** @var string */
    public const STATUS = "{" . self::NSDAV . "}status";
    /** @var string */
    public const PROPFIND = "{" . self::NSDAV . "}propfind";
    /** @var string */
    public const PROPSTAT = "{" . self::NSDAV . "}propstat";
    /** @var string */
    public const PROP = "{" . self::NSDAV . "}prop";
    /** @var string */
    public const HREF = "{" . self::NSDAV . "}href";
    /** @var string */
    public const LIMIT = "{" . self::NSCARDDAV . "}limit";
    /** @var string */
    public const NRESULTS = "{" . self::NSCARDDAV . "}nresults";
    /** @var string */
    public const SUPPORTED_FILTER = "{" . self::NSCARDDAV . "}supported-filter";
    /** @var string */
    public const FILTER = "{" . self::NSCARDDAV . "}filter";
    /** @var string */
    public const PROPFILTER = "{" . self::NSCARDDAV . "}prop-filter";
    /** @var string */
    public const PARAMFILTER = "{" . self::NSCARDDAV . "}param-filter";
    /** @var string */
    public const TEXTMATCH = "{" . self::NSCARDDAV . "}text-match";
    /** @var string */
    public const ISNOTDEFINED = "{" . self::NSCARDDAV . "}is-not-defined";
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
