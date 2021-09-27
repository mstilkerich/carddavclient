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
 * Represents XML DAV:response elements as PHP objects.
 *
 * From RFC 4918:
 * Each ’response’ element MUST have an ’href’ element to identify the resource.
 * A Multi-Status response uses one out of two distinct formats for representing the status:
 *
 * 1. A ’status’ element as child of the ’response’ element indicates the status of the message execution for the
 * identified resource as a whole (for instance, see Section 9.6.2). Some method definitions provide information about
 * specific status codes clients should be prepared to see in a response. However, clients MUST be able to handle other
 * status codes, using the generic rules defined in Section 10 of [RFC2616].
 *
 * 2. For PROPFIND and PROPPATCH, the format has been extended using the ’propstat’ element instead of ’status’,
 * providing information about individual properties of a resource. This format is specific to PROPFIND and PROPPATCH,
 * and is described in detail in Sections 9.1 and 9.2.
 *
 * The ’href’ element contains an HTTP URL pointing to a WebDAV resource when used in the ’response’ container. A
 * particular ’href’ value MUST NOT appear more than once as the child of a ’response’ XML element under a ’multistatus’
 * XML element. This requirement is necessary in order to keep processing costs for a response to linear time.
 *
 * Essentially, this prevents having to search in order to group together all the responses by ’href’. There are,
 * however, no requirements regarding ordering based on ’href’ values. The optional precondition/postcondition element
 * and ’responsedescription’ text can provide additional information about this resource relative to the request or
 * result.
 *
 * ```xml
 * <!ELEMENT response (href, ((href*, status)|(propstat+)), error?, responsedescription? , location?) >
 * ```
 *
 * @psalm-immutable
 *
 * @psalm-import-type DeserializedElem from Deserializers
 *
 * @package Internal\XmlElements
 */
abstract class Response implements \Sabre\Xml\XmlDeserializable
{
    /**
     * Deserializes the child elements of a DAV:response element and creates a new instance of the proper subclass of
     * Response.
     */
    public static function xmlDeserialize(\Sabre\Xml\Reader $reader): Response
    {
        $hrefs = [];
        $propstat = [];
        $status = null;

        $children = $reader->parseInnerTree();
        if (is_array($children)) {
            /** @psalm-var DeserializedElem $child */
            foreach ($children as $child) {
                if ($child["value"] instanceof Propstat) {
                    $propstat[] = $child["value"];
                } elseif (strcasecmp($child["name"], XmlEN::HREF) == 0) {
                    if (is_string($child["value"])) {
                        $hrefs[] = $child["value"];
                    }
                } elseif (strcasecmp($child["name"], XmlEN::STATUS) == 0) {
                    if (isset($status)) {
                        throw new XmlParseException("DAV:response contains multiple DAV:status children");
                    }

                    if (is_string($child["value"])) {
                        $status = $child["value"];
                    }
                }
            }
        }

        if (count($hrefs) == 0) {
            throw new XmlParseException("DAV:response contains no DAV:href child");
        }

        /* By RFC 6578, there must be either a status OR a propstat child element.
         *
         * In practice however, we see the following uncompliances:
         *
         * Sabre/DAV always adds a propstat member, so for a 404 status, we will get an additional propstat with a
         * pseudo status <d:status>HTTP/1.1 418 I'm a teapot</d:status>.
         *
         * SOGO on the other hand adds a status for answers where only a propstat is expected (new or changed items).
         *
         * To enable interoperability, we apply the following heuristic:
         *
         * 1) If we have a 404 status child element -> ResponseStatus
         * 2) If we have a propstat element -> ResponsePropstat
         * 3) If we have a status -> ResponseStatus
         * 4) Error
         */
        if (isset($status) && (stripos($status, " 404 ") !== false)) {
            // Disable this exception for now as Sabre/DAV always inserts a propstat element to a response element
            //if (count($propstat) > 0) {
            //    throw new XmlParseException("DAV:response contains both DAV:status and DAV:propstat children");
            //}

            return new ResponseStatus($hrefs, $status);
        } elseif (count($propstat) > 0) {
            if (count($hrefs) > 1) {
                throw new XmlParseException("Propstat-type DAV:response contains multiple DAV:href children");
            }

            return new ResponsePropstat($hrefs[0], $propstat);
        } elseif (isset($status)) {
            return new ResponseStatus($hrefs, $status);
        }

        throw new XmlParseException("DAV:response contains neither DAV:status nor DAV:propstat children");
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
