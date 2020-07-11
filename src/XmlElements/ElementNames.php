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

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

/**
 * Class ElementNames.
 *
 * This class serves merely as a collection of constants containing fully qualified XML
 * element names used inside this library in clark notation, i.e. including the namespace
 * as a braced prefix.
 */
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
    public const STATUS = "{" . self::NSDAV . "}status";
    public const PROPFIND = "{" . self::NSDAV . "}propfind";
    public const PROPSTAT = "{" . self::NSDAV . "}propstat";
    public const PROP = "{" . self::NSDAV . "}prop";
    public const HREF = "{" . self::NSDAV . "}href";
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
