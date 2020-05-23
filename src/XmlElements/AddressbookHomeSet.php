<?php

/**
 * Class to represent XML CARDDAV:addressbook-home-set elements as PHP objects. (RFC6352)
 *
 * Per RFC6352, this Element should have zero or more DAV:href elements.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\XmlElements;

class AddressbookHomeSet implements \Sabre\Xml\XmlDeserializable
{
    /** @var array URLs of collections that are either address book collections
     * or ordinary collections that have child or descendant address book
     * collections owned by the principal
     */
    public $href = [];

    public static function xmlDeserialize(\Sabre\Xml\Reader $reader)
    {
        $ahs = new self();
        $children = $reader->parseInnerTree();
        if (is_array($children)) {
            foreach ($children as $child) {
                if (strcasecmp($child["name"], '{DAV:}href') == 0) {
                    $ahs->href[] = $child["value"];
                }
            }
        }
        return $ahs;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
