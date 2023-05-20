<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

use MStilkerich\CardDavClient\Config;
use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

/**
 * Represents XML DAV:prop elements as PHP objects.
 *
 * @psalm-import-type DeserializedElem from Deserializers
 *
 * @psalm-type PropTypes = array{
 *   '{DAV:}add-member'?: string,
 *   '{DAV:}current-user-principal'?: string,
 *   '{DAV:}getetag'?: string,
 *   '{DAV:}resourcetype'?: list<string>,
 *   '{DAV:}supported-report-set'?: list<string>,
 *   '{DAV:}sync-token'?: string,
 *   '{DAV:}displayname'?: string,
 *   '{urn:ietf:params:xml:ns:carddav}supported-address-data'?: list<array{'content-type': string, version: string}>,
 *   '{http://calendarserver.org/ns/}getctag'?: string,
 *   '{urn:ietf:params:xml:ns:carddav}address-data'?: string,
 *   '{urn:ietf:params:xml:ns:carddav}addressbook-description'?: string,
 *   '{urn:ietf:params:xml:ns:carddav}max-resource-size'?: int,
 *   '{urn:ietf:params:xml:ns:carddav}addressbook-home-set'?: list<string>,
 * }
 *
 * @package Internal\XmlElements
 */
class Prop implements \Sabre\Xml\XmlDeserializable
{
    /* Currently used properties and types
     *
     * Contains child elements where we are interested in the element names:
     * XmlEN::RESTYPE - Contains one child element per resource type, e.g. <DAV:collection/>
     * XmlEN::SUPPORTED_REPORT_SET - Contains supported-report elements
     *   - XmlEN::SUPPORTED_REPORT - Contains report elements
     *     - XmlEN::REPORT - Contains a child element that indicates the report, e.g. <DAV:sync-collection/>
     *
     * Contains one or more hrefs:
     * XmlEN::ADD_MEMBER - Contains one href child element
     * XmlEN::CURUSRPRINC - Contains one href child element (might also contain an unauthenticated element instead)
     * XmlEN::ABOOK_HOME - Contains one or more href child elements
     *
     * Contains string value:
     * XmlEN::ABOOK_DESC - Contains addressbook description as string
     * XmlEN::ADDRDATA - When part of a REPORT response (our use case), contains the address object data as string
     * XmlEN::DISPNAME - Contains resource displayname as string
     * XmlEN::GETCTAG - Contains the CTag as string
     * XmlEN::GETETAG - Contains the ETag as string
     * XmlEN::SYNCTOKEN - Contains the sync-token as string
     *
     * Contains numeric string value:
     * XmlEN::MAX_RESSIZE - Contains maximum size of an address object resource as a numeric string (positive int)
     *
     * XmlEN::SUPPORTED_ADDRDATA - Address object formats supported by server. Contains address-data-type elements with
     *                             attributes content-type and version
     */

    /**
     * Deserializers for various child elements of prop.
     *
     * @psalm-var array<string, callable(\Sabre\Xml\Reader):void | class-string<\Sabre\Xml\XmlDeserializable>>
     * @var array<string, callable|string>
     */
    public const PROP_DESERIALIZERS = [
        XmlEN::ABOOK_HOME => [ Deserializers::class, 'deserializeHrefMulti' ],
        XmlEN::ADD_MEMBER => [ Deserializers::class, 'deserializeHrefSingle' ],
        XmlEN::CURUSRPRINC => [ Deserializers::class, 'deserializeHrefSingle' ],
        XmlEN::RESTYPE => '\Sabre\Xml\Deserializer\enum',
        XmlEN::SUPPORTED_REPORT_SET => [ Deserializers::class, 'deserializeSupportedReportSet' ],
        XmlEN::SUPPORTED_ADDRDATA => [ Deserializers::class, 'deserializeSupportedAddrData' ],
        XmlEN::ADDRDATATYPE => [ Deserializers::class, 'deserializeToAttributes' ],
    ];

    /**
     * The child elements of this Prop element.
     * Maps child element name to a child-element specific value.
     * @psalm-var PropTypes
     * @var array<string, mixed>
     */
    public $props = [];

    /**
     * Deserializes the child elements of a DAV:prop element and creates a new instance of Prop.
     */
    public static function xmlDeserialize(\Sabre\Xml\Reader $reader)
    {
        $prop = new self();
        $children = $reader->parseInnerTree();
        if (is_array($children)) {
            /** @psalm-var DeserializedElem $child */
            foreach ($children as $child) {
                $prop->storeProperty($child);
            }
        }
        return $prop;
    }

    /**
     * Processes a deserialized prop child element.
     *
     * If the child element is known to this class, the deserialized value is stored to {@see Prop::$props}.
     *
     * @psalm-param DeserializedElem $deserElem
     * @param array $deserElem
     */
    private function storeProperty(array $deserElem): void
    {
        $name = $deserElem["name"];
        $err = false;

        if (!isset($deserElem["value"])) {
            return;
        }

        switch ($name) {
            // Elements where content is a string
            case XmlEN::ADD_MEMBER:
            case XmlEN::CURUSRPRINC:
            case XmlEN::ABOOK_DESC:
            case XmlEN::ADDRDATA:
            case XmlEN::DISPNAME:
            case XmlEN::GETCTAG:
            case XmlEN::GETETAG:
            case XmlEN::SYNCTOKEN:
                if (is_string($deserElem["value"])) {
                    $this->props[$name] = $deserElem["value"];
                } else {
                    $err = true;
                }
                break;

            case XmlEN::MAX_RESSIZE:
                if (is_string($deserElem["value"]) && preg_match("/^\d+$/", $deserElem["value"])) {
                    $this->props[$name] = intval($deserElem["value"]);
                } else {
                    $err = true;
                }
                break;

            // Elements where content is a list of strings
            case XmlEN::ABOOK_HOME:
            case XmlEN::RESTYPE:
            case XmlEN::SUPPORTED_REPORT_SET:
                if (is_array($deserElem["value"])) {
                    $strings = [];
                    foreach (array_keys($deserElem["value"]) as $i) {
                        if (is_string($deserElem["value"][$i])) {
                            $strings[] = $deserElem["value"][$i];
                        } else {
                            $err = true;
                        }
                    }
                    $this->props[$name] = $strings;
                } else {
                    $err = true;
                }
                break;

            // Special handling
            case XmlEN::SUPPORTED_ADDRDATA:
                if (!isset($this->props[XmlEN::SUPPORTED_ADDRDATA])) {
                    $this->props[XmlEN::SUPPORTED_ADDRDATA] = [];
                }

                if (is_array($deserElem["value"])) {
                    foreach (array_keys($deserElem["value"]) as $i) {
                        if (is_array($deserElem["value"][$i])) {
                            $addrDataXml = $deserElem["value"][$i];
                            $addrData = [ 'content-type' => 'text/vcard', 'version' => '3.0' ]; // defaults
                            foreach (['content-type', 'version'] as $a) {
                                if (isset($addrDataXml[$a]) && is_string($addrDataXml[$a])) {
                                    $addrData[$a] = $addrDataXml[$a];
                                }
                            }
                            $this->props[XmlEN::SUPPORTED_ADDRDATA][] = $addrData;
                        }
                    }
                } else {
                    $err = true;
                }
                break;

            default:
                $err = true;
                break;
        }

        if ($err) {
            Config::$logger->warning(
                "Ignoring unexpected content for property $name: " . print_r($deserElem["value"], true)
            );
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
